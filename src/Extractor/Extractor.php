<?php

/*
 * This file is part of JoliCode's Harvest OpenAPI Generator project.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JoliCode\Extractor\Extractor;

use Symfony\Component\DomCrawler\Crawler;

class Extractor
{
    const BASE_TYPES = ['integer', 'string'];
    const DOMAIN = 'https://help.getharvest.com';
    private $definitions = [];
    private $paths = [];

    public function extract()
    {
        $this->definitions = [];
        $this->paths = [];

        $crawler = new Crawler(file_get_contents(self::DOMAIN.'/api-v2/'));
        $links = $crawler->filter('a[href^="/api-v2/"][href*="-api/"]')->extract(['href']);

        foreach ($links as $link) {
            $this->extractApiDoc(self::DOMAIN.$link);
        }

        $this->buildPluralDefinitions();
        $this->buildItemsTypes();

        return [
            'definitions' => $this->definitions,
            'paths' => $this->paths,
        ];
    }

    public static function buildDefinitionProperties($propertyTexts)
    {
        $properties = [];

        while ($name = array_shift($propertyTexts)) {
            $type = array_shift($propertyTexts);

            if ('quantity' === $name) {
                // quantitiy properties are sometimes wrongly documented as integer
                $type = 'float';
            }

            $description = array_shift($propertyTexts);
            $properties[$name] = self::buildDefinitionProperty($name, $type, $description);
        }

        return $properties;
    }

    public static function buildDefinitionProperty($name, $type, $description)
    {
        $arrayof = null;
        $fixedType = self::convertType($type);
        $format = self::detectFormat($name, $type);

        if ('array' === $type) {
            if (preg_match('/^Array of (.+)$/', $description, $matches)) {
                $arrayof = self::singularize(self::camelize($matches[1]));
            }

            if ('Array of task assignment objects associated with the project.' === $description) {
                $arrayof = 'TaskAssignment';
            }
        }

        if ('array of integers' === $type) {
            $arrayof = 'integer';
        }

        if ('array of strings' === $type) {
            $arrayof = 'string';
        }

        $property = [
            'type' => $fixedType,
            'description' => $description,
        ];

        if ('line_items' === $name) {
            $property['items'] = [
                'type' => 'object',
                'required' => [
                    'project_ids',
                ],
                'properties' => [
                    'project_ids' => [
                        'description' => 'An array of the client’s project IDs you’d like to include time/expenses from.',
                        'type' => 'array',
                        'items' => [
                            'type' => 'integer',
                        ],
                    ],
                    'time' => [
                        'description' => 'An time import object.',
                        'type' => 'object',
                        'required' => [
                            'summary_type',
                        ],
                        'properties' => [
                            'summary_type' => [
                                'type' => 'string',
                                'description' => 'How to summarize the time entries per line item. Options: project, task, people, or detailed.',
                            ],
                            'from' => [
                                'type' => 'string',
                                'format' => 'date',
                                'description' => 'Start date for included time entries. Must be provided if to is present. If neither from or to are provided, all unbilled time entries will be included.',
                            ],
                            'to' => [
                                'type' => 'string',
                                'format' => 'date',
                                'description' => 'End date for included time entries. Must be provided if from is present. If neither from or to are provided, all unbilled time entries will be included.',
                            ],
                        ],
                    ],
                    'expenses' => [
                        'description' => 'An expense import object.',
                        'type' => 'object',
                        'required' => [
                            'summary_type',
                        ],
                        'properties' => [
                            'summary_type' => [
                                'type' => 'string',
                                'description' => 'How to summarize the expenses per line item. Options: project, category, people, or detailed.',
                            ],
                            'from' => [
                                'type' => 'string',
                                'format' => 'date',
                                'description' => 'Start date for included expenses. Must be provided if to is present. If neither from or to are provided, all unbilled expenses will be included.',
                            ],
                            'to' => [
                                'type' => 'string',
                                'format' => 'date',
                                'description' => 'End date for included expenses. Must be provided if from is present. If neither from or to are provided, all unbilled expenses will be included.',
                            ],
                            'attach_receipt' => [
                                'type' => 'boolean',
                                'description' => 'If set to true, a PDF containing an expense report with receipts will be attached to the invoice. Defaults to false.',
                            ],
                        ],
                    ],
                ],
            ];
        }

        if (null !== $format) {
            $property['format'] = $format;
        }

        if (null !== $arrayof) {
            $property['arrayof'] = $arrayof;
        }

        if ('object' === $type) {
            $desc = str_replace(',,', ',', str_replace(' and ', ', ', $description));
            $desc = str_replace('has been invoiced, this field', 'has been invoiced this field', $desc);
            $desc = str_replace('file name.', 'file_name', $desc);

            if (preg_match('/^A (.+) object of the/', $description, $matches)) {
                $property['objectoftype'] = self::singularize(self::camelize($matches[1]));
            } elseif (preg_match('/(?:([a-zA-Z_]+), )+([a-zA-Z_]+)/', $desc, $matches)) {
                $matches = explode(', ', $matches[0]);
                $matches = array_flip($matches);
                array_walk($matches, function (&$item, $key) use ($name) {
                    $item = ['type' => self::guessFieldType($key, $name)];
                });

                $property['properties'] = $matches;
            } elseif (preg_match('/^An object containing(?:.*) ([a-zA-Z_]+).$/', $desc, $matches)) {
                $property['properties'] = [$matches[1] => [
                    'type' => self::guessFieldType($matches[1]),
                ]];
            } else {
                echo "$name\t$desc\n";
            }
        }

        return $property;
    }

    public function buildPath($url, $path, $method, $node)
    {
        $description = [];
        $parentNode = $node->parents()->filter('.highlighter-rouge')->first();

        foreach ($parentNode->previousAll() as $previous) {
            if ('h2' === $previous->tagName) {
                $summary = $previous->textContent;
                $summaryId = $previous->getAttribute('id');
                break;
            }

            $description[] = $previous->textContent;
        }

        $pathParameters = [];
        $explicitParameters = [];
        $explicitParametersColumns = [];

        if (preg_match_all('/{([a-zA-Z]+)}/', $path, $matches)) {
            $pathParameters = $matches[1];
        }

        $properties = $parentNode->nextAll()->first();

        if ($properties && 'table' === $properties->getNode(0)->tagName) {
            $explicitParameters = $properties
                ->filter('tbody tr td')
                ->each(function (Crawler $node2, $i) {
                    return $node2->text();
                });
            $explicitParametersColumns = $properties
                ->filter('thead tr th')
                ->each(function (Crawler $node2, $i) {
                    return strtolower($node2->text());
                });
        }

        return [
            'summary' => $summary,
            'operationId' => self::buildOperationId($method, $summary),
            'description' => implode("\n\n", array_reverse($description)),
            'externalDocs' => [
                'description' => $summary,
                'url' => $url.'#'.$summaryId,
            ],
            'security' => [
                [
                    'BearerAuth' => [],
                    'AccountAuth' => [],
                ],
            ],
            'parameters' => self::buildPathParameters($method, $pathParameters, $explicitParameters, $explicitParametersColumns),
            'responses' => self::buildPathResponse($method, $summary),
        ];
    }

    public static function buildPathParameters($method, $pathParameters, $explicitParameters, $explicitParametersColumns)
    {
        $parameters = [];

        foreach ($pathParameters as $pathParameter) {
            $parameters[] = self::buildPathPathParameter($pathParameter);
        }

        if (\count($explicitParameters) > 0) {
            if (4 === \count($explicitParametersColumns) || 'patch' === $method) {
                $parameters[] = self::buildPathBodyParameter($explicitParameters, $explicitParametersColumns);
            } elseif (3 === \count($explicitParametersColumns)) {
                while (\count($explicitParameters) > 0) {
                    foreach ($explicitParametersColumns as $columnName) {
                        $$columnName = array_shift($explicitParameters);
                    }

                    $parameters[] = self::buildPathQueryParameter($parameter, $type, $description);
                }
            }
        }

        return $parameters;
    }

    public static function buildPathBodyParameter($explicitParameters, $explicitParametersColumns)
    {
        $requiredProperties = [];
        $properties = [];

        while (\count($explicitParameters) > 0) {
            foreach ($explicitParametersColumns as $columnName) {
                if ('attribute' === $columnName) {
                    $columnName = 'parameter';
                }

                $$columnName = array_shift($explicitParameters);
            }

            if (isset($required) && 'required' === $required) {
                $requiredProperties[] = $parameter;
            }

            $property = self::buildDefinitionProperty($parameter, $type, $description);
            $properties[$parameter] = $property;
        }

        $result = [
            'name' => 'payload',
            'description' => 'json payload',
            'required' => true,
            'in' => 'body',
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
            ],
        ];

        if (\count($requiredProperties) > 0) {
            $result['schema']['required'] = $requiredProperties;
        }

        return $result;
    }

    public static function buildPathPathParameter($name)
    {
        return [
            'name' => $name,
            'required' => true,
            'in' => 'path',
            'type' => 'string',
        ];
    }

    public static function buildPathQueryParameter($name, $type, $description)
    {
        return [
            'name' => $name,
            'description' => $description,
            'required' => false,
            'in' => 'query',
            'type' => self::convertType($type),
        ];
    }

    public static function buildOperationId($method, $summary)
    {
        $summary = str_replace([' all ', ' an ', ' a '], ' ', $summary);
        $summary = str_replace('’s', '', $summary);

        return lcfirst(self::camelize($summary));
    }

    public static function buildPathResponse($method, $summary)
    {
        $successResponse = [
            'description' => $summary,
        ];
        $successResponseSchema = self::guessPathResponseSchema($summary);

        if ($successResponseSchema) {
            $successResponse['schema'] = [
                '$ref' => $successResponseSchema,
            ];
        }

        return [
            self::guessPathResponseStatus($method, $summary) => $successResponse,
            'default' => [
                'description' => 'error payload',
                'schema' => [
                    '$ref' => '#/definitions/Error',
                ],
            ],
        ];
    }

    public static function camelize($word)
    {
        $separators = ['-', '_', ' '];

        return trim(str_replace($separators, '', ucwords(strtolower($word), implode('', $separators))), " \t.");
    }

    public static function convertType($type)
    {
        $conversionMap = [
            'file' => 'string',
            'long' => 'integer',
            'decimal' => 'number',
            'float' => 'number',
            'double' => 'number',
            'date' => 'string',
            'time' => 'string',
            'datetime' => 'string',
            'dateTime' => 'string',
            'array of integers' => 'array',
            'array of strings' => 'array',
        ];

        return isset($conversionMap[$type]) ? $conversionMap[$type] : $type;
    }

    public static function detectFormat($name, $type)
    {
        $format = null;

        if ('email' === $name) {
            $format = 'email';
        }

        if ('integer' === $type) {
            $format = 'int32';
        }

        if ('long' === $type) {
            $format = 'int64';
        }

        if ('decimal' === $type || 'float' === $type) {
            $format = 'float';
        }

        if ('double' === $type) {
            $format = 'float';
        }

        if ('date' === $type) {
            $format = 'date';
        }

        if ('datetime' === $type || 'dateTime' === $type) {
            $format = 'date-time';
        }

        return $format;
    }

    public static function guessFieldType($name, $objectName = null)
    {
        if ('id' === $name) {
            if ('external_reference' === $objectName) {
                return 'string';
            }

            return 'integer';
        }

        return 'string';
    }

    public static function guessPathResponseSchema($summary)
    {
        $guesser = function ($summary) {
            if (preg_match('/^Create an? ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/definitions/'.self::camelize($matches[1]);
            }

            if (preg_match('/^Retrieve an? ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/definitions/'.self::camelize($matches[1]);
            }

            if (preg_match('/^Update an? ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/definitions/'.self::camelize($matches[1]);
            }

            if (preg_match('/^List (?:all|active) ([a-zA-Z ]+) for an? ([a-zA-Z]+)/', $summary, $matches)) {
                if ('specific' === $matches[2]) {
                    return '#/definitions/'.self::camelize($matches[1]);
                }

                return '#/definitions/'.self::camelize($matches[2].' '.$matches[1]);
            }

            if (preg_match('/^List (?:all|active) ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/definitions/'.self::camelize($matches[1]);
            }

            if ('Retrieve the currently authenticated user' === $summary) {
                return '#/definitions/User';
            }

            return null;
        };

        $result = $guesser($summary);

        if ('#/definitions/TimeEntryViaStartAndEndTime' === $result) {
            $result = '#/definitions/TimeEntry';
        }

        if ('#/definitions/ProjectAssignmentsForTheCurrentlyAuthenticatedUser' === $result) {
            $result = '#/definitions/ProjectAssignments';
        }

        if ('#/definitions/InvoiceBasedOnTrackedTimeAndExpenses' === $result) {
            $result = '#/definitions/Invoice';
        }

        return $result;
    }

    public static function guessPathResponseStatus($method, $summary)
    {
        if (0 === strpos($summary, 'Create a')) {
            return 201;
        }

        return 200;
    }

    public static function pluralize($word)
    {
        if (self::endsWith($word, 'y')) {
            return substr($word, 0, \strlen($word) - 1).'ies';
        }

        return $word.'s';
    }

    public static function singularize($word)
    {
        if (self::endsWith($word, 'ies')) {
            return substr($word, 0, \strlen($word) - 3).'y';
        }

        if (self::endsWith($word, 's')) {
            return substr($word, 0, \strlen($word) - 1);
        }

        return $word;
    }

    public static function snakeCase($word)
    {
        return strtolower(preg_replace('/[A-Z]/', '_\\0', lcfirst($word)));
    }

    public static function endsWith($haystack, $needle)
    {
        $length = \strlen($needle);

        if (0 === $length) {
            return true;
        }

        return substr($haystack, -$length) === $needle;
    }

    private function buildItemsTypes()
    {
        foreach ($this->definitions as $definitionName => $definition) {
            foreach ($definition['properties'] as $propertyName => $property) {
                if (isset($property['arrayof'])) {
                    if (isset($this->definitions[$property['arrayof']])) {
                        $this->definitions[$definitionName]['properties'][$propertyName]['items'] = ['$ref' => '#/definitions/'.$property['arrayof']];
                    }

                    if (\in_array($property['arrayof'], self::BASE_TYPES, true)) {
                        $this->definitions[$definitionName]['properties'][$propertyName]['items'] = ['type' => $property['arrayof']];
                    }

                    unset($this->definitions[$definitionName]['properties'][$propertyName]['arrayof']);
                }

                if (isset($property['objectoftype'])) {
                    if (isset($this->definitions[$property['objectoftype']])) {
                        $this->definitions[$definitionName]['properties'][$propertyName]['$ref'] = '#/definitions/'.$property['objectoftype'];
                    }

                    unset($this->definitions[$definitionName]['properties'][$propertyName]['objectoftype']);
                }
            }
        }

        foreach ($this->paths as $pathName => $path) {
            foreach ($path as $methodName => $method) {
                foreach ($method['parameters'] as $id => $parameter) {
                    if (isset($parameter['schema']) && isset($parameter['schema']['properties'])) {
                        foreach ($parameter['schema']['properties'] as $propertyName => $property) {
                            if (isset($property['arrayof'])) {
                                if (isset($this->definitions[$property['arrayof']])) {
                                    $this->paths[$pathName][$methodName]['parameters'][$id]['schema']['properties'][$propertyName]['items'] = ['$ref' => '#/definitions/'.$property['arrayof']];
                                }

                                if (\in_array($property['arrayof'], self::BASE_TYPES, true)) {
                                    $this->paths[$pathName][$methodName]['parameters'][$id]['schema']['properties'][$propertyName]['items'] = ['type' => $property['arrayof']];
                                }

                                unset($this->paths[$pathName][$methodName]['parameters'][$id]['schema']['properties'][$propertyName]['arrayof']);
                            }

                            if (isset($property['objectoftype'])) {
                                if (isset($this->definitions[$property['objectoftype']])) {
                                    $this->paths[$pathName][$methodName]['parameters'][$id]['schema']['properties'][$propertyName]['$ref'] = '#/definitions/'.$property['objectoftype'];
                                }

                                unset($this->paths[$pathName][$methodName]['parameters'][$id]['schema']['properties'][$propertyName]['objectoftype']);
                            }
                        }
                    }
                }
            }
        }
    }

    private function buildPluralDefinitions()
    {
        foreach ($this->definitions as $name => $definition) {
            $pluralized = self::pluralize(self::snakeCase($name));
            $this->definitions[self::pluralize($name)] = [
                'type' => 'object',
                'required' => [
                    $pluralized,
                    'per_page',
                    'total_pages',
                    'total_entries',
                    'next_page',
                    'previous_page',
                    'page',
                    'links',
                ],
                'properties' => [
                    $pluralized => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => '#/definitions/'.$name,
                        ],
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'total_pages' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'total_entries' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'next_page' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'previous_page' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'page' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'links' => [
                        '$ref' => '#/definitions/PaginationLinks',
                    ],
                ],
            ];
        }
    }

    private function extractApiDoc($url)
    {
        $crawler = new Crawler(file_get_contents($url));

        $crawler->filter('h2[id^="the-"][id$="-object"]')->each(function (Crawler $node, $i) use ($url) {
            if (preg_match('/^the-(.+)-object$/', $node->attr('id'), $matches)) {
                $definitionName = self::camelize($matches[1]);
                $this->definitions[$definitionName] = [
                    'type' => 'object',
                    'externalDocs' => [
                        'description' => $matches[1],
                        'url' => $url.'#'.$node->attr('id'),
                    ],
                    'properties' => self::buildDefinitionProperties($node->nextAll()
                        ->first()
                        ->filter('tbody tr td')
                        ->each(function (Crawler $node2, $i) {
                            return $node2->text();
                        })),
                ];
            }
        });

        $crawler->filter('div.highlighter-rouge pre.highlight code')->each(function (Crawler $node, $i) use ($url) {
            $text = trim($node->text());

            if (preg_match('/^(GET|POST|PATCH|DELETE) \/v2(\/.*)/', $text, $matches)) {
                $method = strtolower($matches[1]);
                $path = preg_replace_callback('/{([a-zA-Z_]+)}/', function ($property) {
                    return '{'.lcfirst(self::camelize($property[1])).'}';
                }, $matches[2]);

                if (!isset($this->paths[$path])) {
                    $this->paths[$path] = [];
                }

                $this->paths[$path][$method] = self::buildPath($url, $path, $method, $node);
            }
        });
    }
}
