<?php

/*
 * This file is part of JoliCode's Harvest OpenAPI Generator project.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JoliCode\Extractor\Dumper;

use Symfony\Component\Yaml\Yaml;

class Dumper
{
    private string $target;

    public function __construct(string $target)
    {
        $this->target = $target;
    }

    public function dump(array $data): array
    {
        $data = [
            'openapi' => '3.0.1',
            'info' => [
                'version' => '1.0.0',
                'title' => 'Harvestapp API',
                'license' => ['name' => 'MIT'],
            ],
            'externalDocs' => [
                'description' => 'Learn more about the Harvest Web API',
                'url' => 'https://help.getharvest.com/api-v2/',
            ],
            'servers' => [[
                'url' => 'https://api.harvestapp.com/v2',
            ]],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'Authorization',
                    ],
                    'AccountAuth' => [
                        'type' => 'apiKey',
                        'in' => 'header',
                        'name' => 'Harvest-Account-Id',
                    ],
                ],
                'schemas' => $data['schemas'],
            ],
            'security' => [
                [
                    'BearerAuth' => [],
                    'AccountAuth' => [],
                ],
            ],
            'paths' => $data['paths'],
        ];
        $definitionOverrides = [
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'code' => [
                        'type' => 'integer',
                    ],
                    'message' => [
                        'type' => 'string',
                    ],
                ],
            ],
            'Expense' => [
                'properties' => [
                    'total_cost' => [
                        'type' => 'number',
                        'description' => 'The total amount of the expense.',
                        'format' => 'float',
                        'nullable' => true,
                    ],
                    'units' => [
                        'type' => 'integer',
                        'description' => 'The quantity of units to use in calculating the total_cost of the expense.',
                        'format' => 'int32',
                        'nullable' => true,
                    ],
                    'receipt' => [
                        'properties' => [
                            'file_size' => [
                                'type' => 'integer',
                                'format' => 'int32',
                                'nullable' => true,
                            ],
                            'content_type' => [
                                'type' => 'string',
                                'nullable' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'PaginationLinks' => [
                'type' => 'object',
                'required' => ['first', 'last'],
                'properties' => [
                    'first' => [
                        'type' => 'string',
                        'format' => 'url',
                        'description' => 'First page',
                    ],
                    'last' => [
                        'type' => 'string',
                        'format' => 'url',
                        'description' => 'Last page',
                    ],
                    'previous' => [
                        'type' => 'string',
                        'format' => 'url',
                        'description' => 'Previous page',
                        'nullable' => true,
                    ],
                    'next' => [
                        'type' => 'string',
                        'format' => 'url',
                        'description' => 'Next page',
                        'nullable' => true,
                    ],
                ],
            ],
        ];
        $warnings = [];

        foreach ($definitionOverrides as $definition => $override) {
            if (isset($data['components']['schemas'][$definition])) {
                if (isset($override['properties'])) {
                    foreach ($override['properties'] as $propertyName => $propertyOverride) {
                        if (
                            isset($data['components']['schemas'][$definition]['properties'])
                            && isset($data['components']['schemas'][$definition]['properties'][$propertyName])
                        ) {
                            $warnings[] = sprintf(
                                'The property "%s" of the definition "%s" already exists and has been overriden.',
                                $propertyName,
                                $definition
                            );

                            if (
                                isset($data['components']['schemas'][$definition]['properties'][$propertyName]['properties'])
                                && isset($propertyOverride['properties'])
                            ) {
                                $data['components']['schemas'][$definition]['properties'][$propertyName]['properties'] = array_merge(
                                    $data['components']['schemas'][$definition]['properties'][$propertyName]['properties'],
                                    $propertyOverride['properties']
                                );
                                unset($propertyOverride['properties']);
                            }

                            $data['components']['schemas'][$definition]['properties'][$propertyName] = array_merge(
                                $data['components']['schemas'][$definition]['properties'][$propertyName],
                                $propertyOverride
                            );
                        } else {
                            $data['components']['schemas'][$definition]['properties'][$propertyName] = $propertyOverride;
                        }
                    }
                }
            } else {
                $data['components']['schemas'][$definition] = $override;
            }
        }

        file_put_contents(
            $this->target,
            preg_replace(
                '#!!float (\d+)#',
                '\1.0',
                Yaml::dump($data, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE)
            )
        );

        return $warnings;
    }
}
