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

        $this->paths = array_map(function ($path) {
            ksort($path);

            return $path;
        },
        $this->paths);
        ksort($this->paths);

        $this->printUnknownDefinitions($this->paths);
        $this->printOperationsIdList($this->paths);

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

    public static function buildDefinitionProperty($name, $type, $description, $path = null, $method = null)
    {
        $arrayof = null;
        $fixedType = self::convertType($type);
        $format = self::detectFormat($name, $type);

        $property = [
            'type' => $fixedType,
            'description' => $description,
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

        if (null !== $arrayof) {
            $property['arrayof'] = $arrayof;
        }

        if ('Array of recipient parameters. See below for details.' === $description) {
            unset($property['arrayof']);
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
                unset($property['arrayof']);
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
                unset($property['arrayof']);
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
                unset($property['arrayof']);
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
                unset($property['arrayof']);
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
                $matches = array_flip($matches);
                array_walk($matches, function (&$item, $key) use ($name) {
                    $item = ['type' => self::guessFieldType($key, $name)];
                });

                $property['properties'] = $matches;
            } elseif (preg_match('/^An object containing(?:.*) ([a-zA-Z_]+).$/', $desc, $matches)) {
                $property['properties'] = [$matches[1] => [
                    'type' => self::guessFieldType($matches[1]),
                ]];
            } elseif (!isset($property['properties'])) {
                echo "$name\t$desc\n";
            }
        }

        return $property;
    }

    public static function buildPath($url, $path, $method, $node, $title)
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
            'parameters' => self::buildPathParameters($method, $path, $pathParameters, $explicitParameters, $explicitParametersColumns),
            'responses' => self::buildPathResponse($method, $summary, $title),
        ];
    }

    public static function buildPathParameters($method, $path, $pathParameters, $explicitParameters, $explicitParametersColumns)
    {
        $parameters = [];

        foreach ($pathParameters as $pathParameter) {
            $parameters[] = self::buildPathPathParameter($pathParameter);
        }

        if (\count($explicitParameters) > 0) {
            if (\in_array($method, ['patch', 'post'], true)) {
                $parameters[] = self::buildPathBodyParameter($method, $path, $explicitParameters, $explicitParametersColumns);
            } else {
                while (\count($explicitParameters) > 0) {
                    $required = false;

                    foreach ($explicitParametersColumns as $columnName) {
                        $$columnName = array_shift($explicitParameters);
                    }

                    $parameters[] = self::buildPathQueryParameter($parameter, $type, $description, $required);
                }
            }
        }

        return $parameters;
    }

    public static function buildPathBodyParameter($method, $path, $explicitParameters, $explicitParametersColumns)
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

            $property = self::buildDefinitionProperty($parameter, $type, $description, $path, $method);
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

    public static function buildPathQueryParameter($name, $type, $description, $required)
    {
        return [
            'name' => $name,
            'description' => $description,
            'required' => ('required' === $required),
            'in' => 'query',
            'type' => self::convertType($type),
        ];
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

    public static function buildPathResponse($method, $summary, $title)
    {
        $successResponse = [
            'description' => self::cleanupSummary($summary),
        ];
        $successResponseSchema = self::guessPathResponseSchema($summary, $title);

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

    public static function guessPathResponseSchema($summary, $title)
    {
        $guesser = function ($summary) use ($title) {
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

            if (preg_match('/^([a-zA-Z ]+) Report/', $summary)) {
                return '#/definitions/'.$title.'Results';
            }

            if ('Retrieve the currently authenticated user' === $summary) {
                return '#/definitions/User';
            }

            return null;
        };

        $result = $guesser($summary);

        if ('#/definitions/Free' === $result) {
            $result = '#/definitions/Invoice';
        }

        if ('#/definitions/TimeEntryViaDuration' === $result) {
            $result = '#/definitions/TimeEntry';
        }

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
                echo "   * $operation\n";
            }
        }
    }

    private function printUnknownDefinitions(array $items)
    {
        foreach ($items as $key => $item) {
            if (\is_array($item)) {
                $this->printUnknownDefinitions($item);
            } elseif ('$ref' === $key) {
                $item = substr($item, 14);

                if (!isset($this->definitions[$item]) && 'Error' !== $item) {
                    throw new \LogicException(sprintf('Unknown definition: %s', $item));
                }
            }
        }
    }

    private static function cleanupOperationId($operationId)
    {
        $conversionMap = [
            'createFreeFormInvoice' => 'createInvoice',
            'createTimeEntryViaDuration' => 'createTimeEntry',
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

    private function buildItemsTypes()
    {
        foreach ($this->definitions as $definitionName => $definition) {
            foreach ($definition['properties'] as $propertyName => $property) {
                if (isset($property['arrayof'])) {
                    if (isset($this->definitions[$property['arrayof']])) {
                        $this->definitions[$definitionName]['properties'][$propertyName]['items'] = ['$ref' => '#/definitions/'.$property['arrayof']];
                    } elseif (\in_array($property['arrayof'], self::BASE_TYPES, true)) {
                        $this->definitions[$definitionName]['properties'][$propertyName]['items'] = ['type' => $property['arrayof']];
                    } else {
                        echo $property['arrayof']."\n";
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
                                } elseif (\in_array($property['arrayof'], self::BASE_TYPES, true)) {
                                    $this->paths[$pathName][$methodName]['parameters'][$id]['schema']['properties'][$propertyName]['items'] = ['type' => $property['arrayof']];
                                } else {
                                    echo sprintf(
                                        "%s %s - %s\n",
                                        $methodName,
                                        $pathName,
                                        $property['arrayof']
                                    );
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

        $title = trim($crawler->filter('h1')->text());

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
                    $bodyParams = array_filter($operation['parameters'], function ($item) {
                        return 'body' === $item['in'];
                    });
                    if (\count($bodyParams) > 0) {
                        foreach ($this->paths[$path][$method]['parameters'] as $key => $parameter) {
                            if ('body' === $parameter['in']) {
                                $this->paths[$path][$method]['parameters'][$key]['schema']['properties'] = array_merge(
                                    array_shift($bodyParams)['schema']['properties'],
                                    $this->paths[$path][$method]['parameters'][$key]['schema']['properties']
                                );

                                return;
                            }
                        }
                    }
                }
            }
        });
    }
}
