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
    public const BASE_TYPES = ['integer', 'string'];
    public const DOMAIN = 'https://help.getharvest.com';
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

        $this->paths = array_map(function ($path) {
            ksort($path);

            return $path;
        },
            $this->paths);
        ksort($this->paths);

        $this->printUnknownDefinitions($this->paths);
        $this->printOperationsIdList($this->paths);

        return [
            'schemas' => $this->definitions,
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

    public static function buildDefinitionProperty($name, $type, $description, $path = null, $method = null)
    {
        $arrayof = null;
        $fixedType = self::convertType($type);
        $format = self::detectFormat($name, $type);

        $property = [
            'type' => $fixedType,
            'description' => $description,
            'nullable' => true,
        ];

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

        if ('array of user ids' === $type) {
            $arrayof = 'string';
        }

        if (null !== $arrayof) {
            $property['items'] = ['type' => $arrayof];
        } elseif ('array' === $type && 'payment_options' === $name) {
            $property['items'] = [
                'type' => 'string',
                'enum' => [
                    'ach',
                    'credit_card',
                    'paypal',
                ],
            ];
        }

        if ('Array of recipient parameters. See below for details.' === $description || 'Array of recipient parameters. See below for more details.' === $description) {
            $property['items'] = [
                'type' => 'object',
                'required' => [
                    'email',
                ],
                'properties' => [
                    'name' => [
                        'description' => 'Name of the message recipient.',
                        'type' => 'string',
                    ],
                    'email' => [
                        'description' => 'Email of the message recipient.',
                        'type' => 'string',
                        'format' => 'email',
                    ],
                ],
            ];
        } elseif ('line_items_import' === $name) {
            $property['required'] = [
                'project_ids',
            ];
            $property['properties'] = [
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
            ];
        } elseif ('line_items' === $name) {
            if ('/invoices' === $path) {
                $property['items'] = [
                    'type' => 'object',
                    'required' => [
                        'kind',
                        'unit_price',
                    ],
                    'properties' => [
                        'project_id' => [
                            'description' => 'The ID of the project associated with this line item.',
                            'type' => 'integer',
                            'format' => 'int32',
                        ],
                        'kind' => [
                            'description' => 'The name of an invoice item category.',
                            'type' => 'string',
                        ],
                        'description' => [
                            'description' => 'Text description of the line item.',
                            'type' => 'string',
                        ],
                        'quantity' => [
                            'description' => 'The unit quantity of the item. Defaults to 1.',
                            'type' => 'number',
                            'format' => 'float',
                        ],
                        'unit_price' => [
                            'description' => 'The individual price per unit.',
                            'type' => 'number',
                            'format' => 'float',
                        ],
                        'taxed' => [
                            'description' => 'Whether the invoice’s tax percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                        'taxed2' => [
                            'description' => 'Whether the invoice’s tax2 percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                    ],
                ];
            } elseif ('/invoices/{invoiceId}' === $path) {
                $property['items'] = [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'description' => 'Unique ID for the line item.',
                            'type' => 'integer',
                            'format' => 'int32',
                        ],
                        'project_id' => [
                            'description' => 'The ID of the project associated with this line item.',
                            'type' => 'integer',
                            'format' => 'int32',
                        ],
                        'kind' => [
                            'description' => 'The name of an invoice item category.',
                            'type' => 'string',
                        ],
                        'description' => [
                            'description' => 'Text description of the line item.',
                            'type' => 'string',
                        ],
                        'quantity' => [
                            'description' => 'The unit quantity of the item. Defaults to 1.',
                            'type' => 'number',
                            'format' => 'float',
                        ],
                        'unit_price' => [
                            'description' => 'The individual price per unit.',
                            'type' => 'number',
                            'format' => 'float',
                        ],
                        'taxed' => [
                            'description' => 'Whether the invoice’s tax percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                        'taxed2' => [
                            'description' => 'Whether the invoice’s tax2 percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                    ],
                ];
            } elseif ('/estimates' === $path) {
                $property['items'] = [
                    'type' => 'object',
                    'required' => [
                        'kind',
                        'unit_price',
                    ],
                    'properties' => [
                        'kind' => [
                            'description' => 'The name of an estimate item category.',
                            'type' => 'string',
                        ],
                        'description' => [
                            'description' => 'Text description of the line item.',
                            'type' => 'string',
                        ],
                        'quantity' => [
                            'description' => 'The unit quantity of the item. Defaults to 1.',
                            'type' => 'integer',
                            'format' => 'int32',
                        ],
                        'unit_price' => [
                            'description' => 'The individual price per unit.',
                            'type' => 'number',
                            'format' => 'float',
                        ],
                        'taxed' => [
                            'description' => 'Whether the estimate’s tax percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                        'taxed2' => [
                            'description' => 'Whether the estimate’s tax2 percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                    ],
                ];
            } elseif ('/estimates/{estimateId}' === $path) {
                $property['items'] = [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'description' => 'Unique ID for the line item.',
                            'type' => 'integer',
                            'format' => 'int32',
                        ],
                        'kind' => [
                            'description' => 'The name of an estimate item category.',
                            'type' => 'string',
                        ],
                        'description' => [
                            'description' => 'Text description of the line item.',
                            'type' => 'string',
                        ],
                        'quantity' => [
                            'description' => 'The unit quantity of the item. Defaults to 1.',
                            'type' => 'integer',
                            'format' => 'int32',
                        ],
                        'unit_price' => [
                            'description' => 'The individual price per unit.',
                            'type' => 'number',
                            'format' => 'float',
                        ],
                        'taxed' => [
                            'description' => 'Whether the estimate’s tax percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                        'taxed2' => [
                            'description' => 'Whether the estimate’s tax2 percentage applies to this line item. Defaults to false.',
                            'type' => 'boolean',
                        ],
                    ],
                ];
            }
        }

        if (null !== $format) {
            $property['format'] = $format;
        }

        if ('object' === $type) {
            $desc = str_replace(',,', ',', str_replace(' and ', ', ', $description));
            $desc = str_replace('has been invoiced, this field', 'has been invoiced this field', $desc);
            $desc = str_replace('file name.', 'file_name', $desc);

            if (preg_match('/^A (.+) object of the/', $description, $matches)) {
                $property['objectoftype'] = self::singularize(self::camelize($matches[1]));
            } elseif (preg_match('/(?:([a-zA-Z_]+), )+([a-zA-Z_]+)/', $desc, $matches)) {
                $matches = explode(', ', $matches[0]);
                $matches = array_flip(array_map('strtolower', $matches));
                array_walk($matches, function (&$item, $key) use ($name) {
                    $item = ['type' => self::guessFieldType($key, $name), 'nullable' => true];
                });

                $property['properties'] = $matches;
            } elseif (preg_match('/^An object containing(?:.*) ([a-zA-Z_]+).$/', $desc, $matches)) {
                $property['properties'] = [$matches[1] => [
                    'type' => self::guessFieldType($matches[1]),
                    'nullable' => true,
                ]];
            } elseif (!isset($property['properties'])) {
                echo "$name\t$desc\n";
            }
        }

        if (str_starts_with($description, 'DEPRECATED')) {
            $property['deprecated'] = true;
        }

        return $property;
    }

    public static function buildPath($url, $path, $method, $node, $title)
    {
        $description = [];
        $parentNode = $node->ancestors()->filter('.highlighter-rouge')->first();
        $summary = '';
        $summaryId = '';

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

        $example = '';

        foreach ($parentNode->nextAll() as $next) {
            if ('h2' === $next->tagName) {
                // break on new section
                break;
            }

            if ('figure' === $next->tagName) {
                $next = self::decodeEmailAddresses($next);
                $example = $next->textContent;
                break;
            }
        }

        $pathData = [
            'summary' => self::cleanupSummary($summary),
            'operationId' => self::cleanupOperationId(
                self::buildOperationId($path, $method, $summary)
            ),
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
            'responses' => self::buildPathResponse($method, $summary, $title, $example),
        ];
        $pathParametersData = self::buildPathParameters($method, $path, $pathParameters, $explicitParameters, $explicitParametersColumns);

        if (\count($pathParametersData)) {
            $pathData['parameters'] = $pathParametersData;
        }

        if (\in_array($method, ['patch', 'post'], true) && \count($explicitParameters) > 0) {
            $pathData['requestBody'] = self::buildRequestBody($method, $path, $explicitParameters, $explicitParametersColumns);
        }

        return $pathData;
    }

    public static function buildPathParameters($method, $path, $pathParameters, $explicitParameters, $explicitParametersColumns)
    {
        $parameters = [];

        foreach ($pathParameters as $pathParameter) {
            $parameters[] = self::buildPathPathParameter($pathParameter);
        }

        if (\count($explicitParameters) > 0 && !\in_array($method, ['patch', 'post'], true)) {
            while (\count($explicitParameters) > 0) {
                $required = false;

                foreach ($explicitParametersColumns as $columnName) {
                    $$columnName = array_shift($explicitParameters);
                }

                $parameters[] = self::buildPathQueryParameter($parameter, $type, $description, $required);

                if ('page' === $parameter && str_starts_with($description, 'DEPRECATED')) {
                    $parameters[] = self::buildPathQueryParameter('cursor', 'string', 'Pagination cursor', false);
                }
            }
        }

        return $parameters;
    }

    public static function buildRequestBody($method, $path, $explicitParameters, $explicitParametersColumns)
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

            if (isset($required)) {
                if ('*optional' === $required) {
                    $required = 'optional';
                }

                if ('required' === $required) {
                    $requiredProperties[] = $parameter;
                } elseif (!\in_array($required, ['optional', 'required'], true)) {
                    $description = $required;
                }
            }

            $property = self::buildDefinitionProperty($parameter, $type, $description, $path, $method);
            $properties[$parameter] = $property;
        }

        $result = [
            'description' => 'json payload',
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $properties,
                    ],
                ],
            ],
        ];

        if (\count($requiredProperties) > 0) {
            $result['content']['application/json']['schema']['required'] = $requiredProperties;
        }

        return $result;
    }

    public static function buildPathPathParameter($name)
    {
        return [
            'name' => $name,
            'required' => true,
            'in' => 'path',
            'schema' => [
                'type' => 'string',
            ],
        ];
    }

    public static function buildPathQueryParameter($name, $type, $description, $required)
    {
        $pathQueryParameter = [
            'name' => $name,
            'description' => $description,
            'required' => ('required' === $required),
            'in' => 'query',
            'schema' => [
                'type' => self::convertType($type),
            ],
        ];

        if (str_starts_with($description, 'DEPRECATED')) {
            $pathQueryParameter['deprecated'] = true;
        }

        return $pathQueryParameter;
    }

    public static function buildOperationId($path, $method, $summary)
    {
        if ('/reports/time/clients' === $path) {
            return 'clientsTimeReport';
        } elseif ('/reports/expenses/clients' === $path) {
            return 'clientsExpensesReport';
        } elseif ('/reports/time/team' === $path) {
            return 'teamTimeReport';
        } elseif ('/reports/expenses/team' === $path) {
            return 'teamExpensesReport';
        } elseif ('/reports/time/projects' === $path) {
            return 'projectsTimeReport';
        } elseif ('/reports/expenses/projects' === $path) {
            return 'projectsExpensesReport';
        }

        $summary = str_replace([' all ', ' an ', ' a '], ' ', $summary);
        $summary = str_replace('’s', '', $summary);

        return lcfirst(self::camelize($summary));
    }

    public static function buildPathResponse($method, $summary, $title, $example)
    {
        $successResponse = [
            'description' => self::cleanupSummary($summary),
        ];
        $successResponseContent = [];
        $successResponseSchema = self::guessPathResponseSchema($summary, $title);

        if ($successResponseSchema) {
            $successResponseContent['schema'] = [
                '$ref' => $successResponseSchema,
            ];
        }

        if ($example = json_decode($example, true)) {
            $successResponseContent['example'] = $example;
        }

        if (\count($successResponseContent) > 0) {
            $successResponse['content'] = [
                'application/json' => $successResponseContent,
            ];
        }

        return [
            self::guessPathResponseStatus($method, $summary) => $successResponse,
            'default' => [
                'description' => 'error payload',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error',
                        ],
                    ],
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
            'bigint' => 'integer',
            'file' => 'string',
            'int' => 'integer',
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
            'array of user ids' => 'array',
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

    public static function guessPathResponseSchema($summary, $title)
    {
        $guesser = function ($summary) use ($title) {
            if ('Update a user’s assigned teammates' === $summary) {
                return '#/components/schemas/TeammatesPatchResponse';
            }

            if (preg_match('/^Create an? ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/components/schemas/'.self::camelize($matches[1]);
            }

            if (preg_match('/^Retrieve an? ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/components/schemas/'.self::camelize($matches[1]);
            }

            if (preg_match('/^Update an? ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/components/schemas/'.self::camelize($matches[1]);
            }

            if (preg_match('/^List (?:all assigned|all|active) ([a-zA-Z ]+) for an? ([a-zA-Z]+)/', $summary, $matches)) {
                if ('specific' === $matches[2]) {
                    return '#/components/schemas/'.self::camelize($matches[1]);
                }

                return '#/components/schemas/'.self::camelize($matches[2].' '.$matches[1]);
            }

            if (preg_match('/^List (?:all|active) ([a-zA-Z ]+)/', $summary, $matches)) {
                return '#/components/schemas/'.self::camelize($matches[1]);
            }

            if (preg_match('/^([a-zA-Z ]+) Report/', $summary)) {
                return '#/components/schemas/'.$title.'Results';
            }

            if (preg_match('/ (:?stopped|running) time entry$/', $summary)) {
                return '#/components/schemas/TimeEntry';
            }

            if ('Retrieve the currently authenticated user' === $summary) {
                return '#/components/schemas/User';
            }

            if ('Retrieve invoice message subject and body for specific invoice' === $summary) {
                return '#/components/schemas/InvoiceMessageSubjectAndBody';
            }

            if ('Create and send an invoice message' === $summary) {
                return '#/components/schemas/InvoiceMessage';
            }

            return null;
        };

        $result = $guesser($summary);

        if ('#/components/schemas/Free' === $result) {
            $result = '#/components/schemas/Invoice';
        }

        if ('#/components/schemas/TimeEntryViaDuration' === $result) {
            $result = '#/components/schemas/TimeEntry';
        }

        if ('#/components/schemas/TimeEntryViaStartAndEndTime' === $result) {
            $result = '#/components/schemas/TimeEntry';
        }

        if ('#/components/schemas/ProjectAssignmentsForTheCurrentlyAuthenticatedUser' === $result) {
            $result = '#/components/schemas/ProjectAssignments';
        }

        if ('#/components/schemas/InvoiceBasedOnTrackedTimeAndExpenses' === $result) {
            $result = '#/components/schemas/Invoice';
        }

        return $result;
    }

    public static function guessPathResponseStatus($method, $summary)
    {
        if (str_starts_with($summary, 'Create a')) {
            return 201;
        }

        return 200;
    }

    public static function pluralize($word)
    {
        if (self::endsWith($word, 'y')) {
            return substr($word, 0, \strlen($word) - 1).'ies';
        }

        if (self::endsWith($word, 'result')) {
            return 'results';
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

    private function printOperationsIdList()
    {
        $operations = [];

        foreach ($this->paths as $pathName => $path) {
            $familyName = ucwords(str_replace('_', ' ', explode('/', $pathName)[1]));

            if (!isset($operations[$familyName])) {
                $operations[$familyName] = [];
            }

            foreach ($path as $methodName => $operation) {
                $operations[$familyName][] = $operation['operationId'];
            }
        }

        foreach ($operations as $family => $familyOperations) {
            echo " * $family\n";

            foreach ($familyOperations as $operation) {
                echo "   * `$operation()`\n";
            }
        }
    }

    private function printUnknownDefinitions(array $items)
    {
        foreach ($items as $key => $item) {
            if (\is_array($item)) {
                $this->printUnknownDefinitions($item);
            } elseif ('$ref' === $key) {
                $item = substr($item, 21);

                if (!isset($this->definitions[$item]) && !\in_array($item, ['Error', 'InvoiceMessageSubjectAndBody', 'TeammatesPatchResponse'], true)) {
                    throw new \LogicException(\sprintf('Unknown definition: %s', $item));
                }
            }
        }
    }

    private static function cleanupOperationId($operationId)
    {
        $conversionMap = [
            'createFreeFormInvoice' => 'createInvoice',
            'createTimeEntryViaDuration' => 'createTimeEntry',
            'createAndSendInvoiceMessage' => 'createInvoiceMessage',
        ];

        return isset($conversionMap[$operationId]) ? $conversionMap[$operationId] : $operationId;
    }

    private static function cleanupSummary($summary)
    {
        $summaries = [
            'Create an invoice message' => 'Create an invoice message or change invoice status',
            'Create a free-form invoice' => 'Create an invoice',
            'Create an estimate message' => 'Create an estimate message or change estimate status',
            'Create a time entry via duration' => 'Create a time entry',
        ];

        return isset($summaries[$summary]) ? $summaries[$summary] : $summary;
    }

    private static function getPower(string $email, int $position): int
    {
        $char = substr($email, $position, 2);

        return \intval($char, 16);
    }

    private static function decodeEmailAddress(string $email): string
    {
        $output = '';
        $power = self::getPower($email, 0);
        $i = 2;

        while ($i < \strlen($email)) {
            $char = self::getPower($email, $i) ^ $power;
            $output .= \chr($char);
            $i += 2;
        }

        return $output;
    }

    private static function decodeEmailAddresses(\DOMElement $next)
    {
        foreach ($next->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if ('a' === $child->tagName && $child->hasAttribute('data-cfemail')) {
                    $child->textContent = self::decodeEmailAddress($child->getAttribute('data-cfemail'));
                }

                $child = self::decodeEmailAddresses($child);
            }
        }

        return $next;
    }

    private function buildItemsTypes()
    {
        foreach ($this->definitions as $definitionName => $definition) {
            foreach ($definition['properties'] as $propertyName => $property) {
                if (isset($property['items']) && isset($property['items']['type'])) {
                    if (isset($this->definitions[$property['items']['type']])) {
                        $this->definitions[$definitionName]['properties'][$propertyName]['items'] = ['$ref' => '#/components/schemas/'.$property['items']['type']];
                    } elseif (!\in_array($property['items']['type'], self::BASE_TYPES, true)) {
                        echo $property['items']['type']."\n";
                    }
                }

                if (isset($property['objectoftype'])) {
                    if (isset($this->definitions[$property['objectoftype']])) {
                        if (isset($property['nullable']) && true === $property['nullable']) {
                            unset($this->definitions[$definitionName]['properties'][$propertyName]['type']);
                            $this->definitions[$definitionName]['properties'][$propertyName]['anyOf'] = [
                                [
                                    'type' => 'object',
                                    '$ref' => '#/components/schemas/'.$property['objectoftype'],
                                ],
                                [
                                    'type' => 'null',
                                ],
                            ];
                        } else {
                            $this->definitions[$definitionName]['properties'][$propertyName]['$ref'] = '#/components/schemas/'.$property['objectoftype'];
                        }
                    }

                    unset($this->definitions[$definitionName]['properties'][$propertyName]['objectoftype']);
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
                            '$ref' => '#/components/schemas/'.$name,
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
                        'nullable' => true,
                    ],
                    'previous_page' => [
                        'type' => 'integer',
                        'format' => 'int64',
                        'nullable' => true,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'format' => 'int64',
                    ],
                    'links' => [
                        '$ref' => '#/components/schemas/PaginationLinks',
                    ],
                ],
            ];
        }
    }

    private function download($url): string
    {
        $key = md5($url);
        $cacheDirectory = __DIR__.'/../../var/download/';
        $path = $cacheDirectory.$key.'.txt';

        if (!is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0700, true);
        }

        if (!file_exists($path)) {
            copy($url, $path);
        }

        return file_get_contents($path);
    }

    private function extractApiDoc($url)
    {
        $crawler = new Crawler($this->download($url));

        $title = trim($crawler->filter('article h1')->text());

        if (preg_match('/^([a-zA-Z ]+) Report(s)?/', $title)) {
            $title = self::camelize($title);
        } else {
            $title = '';
        }

        $crawler->filter('h2[id^="the-"][id$="-object"]')->each(function (Crawler $node, $i) use ($url, $title) {
            if (preg_match('/^the-(.+)-object$/', $node->attr('id'), $matches)) {
                $definitionName = $title.self::camelize($matches[1]);
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

        $crawler->filter('div.highlighter-rouge pre.highlight code')->each(function (Crawler $node, $i) use ($url, $title) {
            $text = trim($node->text());

            if (preg_match('/^(GET|POST|PATCH|DELETE) \/v2(\/.*)/', $text, $matches)) {
                $method = strtolower($matches[1]);
                $path = preg_replace_callback('/{([a-zA-Z_]+)}/', function ($property) {
                    return '{'.lcfirst(self::camelize($property[1])).'}';
                }, $matches[2]);

                if (!isset($this->paths[$path])) {
                    $this->paths[$path] = [];
                }

                $operation = self::buildPath($url, $path, $method, $node, $title);

                if (!isset($this->paths[$path][$method])) {
                    $this->paths[$path][$method] = $operation;
                } else {
                    // add possible additionnal body parameters
                    // this happens to be useful for several api documented
                    // calls to the same endpoint, but with different parameters...
                    if (isset($operation['requestBody'])) {
                        if (isset($this->paths[$path][$method]['requestBody'])) {
                            $this->paths[$path][$method]['requestBody']['content']['application/json']['schema']['properties'] = array_merge(
                                $operation['requestBody']['content']['application/json']['schema']['properties'],
                                $this->paths[$path][$method]['requestBody']['content']['application/json']['schema']['properties']
                            );
                        } else {
                            $this->paths[$path][$method]['requestBody'] = $operation['requestBody'];
                        }
                    }
                }
            }
        });
    }
}
