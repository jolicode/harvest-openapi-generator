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
        $baseData = [
            'swagger' => '2.0',
            'info' => [
                'version' => '1.0.0',
                'title' => 'Harvestapp API',
                'license' => ['name' => 'MIT'],
            ],
            'externalDocs' => [
                'description' => 'Learn more about the Harvest Web API',
                'url' => 'https://help.getharvest.com/api-v2/',
            ],
            'host' => 'api.harvestapp.com',
            'basePath' => '/v2',
            'schemes' => ['https'],
            'securityDefinitions' => [
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
            'security' => [
                [
                    'BearerAuth' => [],
                    'AccountAuth' => [],
                ],
            ],
            'paths' => new \stdClass(),
            'definitions' => new \stdClass(),
        ];

        $data['definitions']['Error'] = [
            'type' => 'object',
            'properties' => [
                'code' => [
                    'type' => 'integer',
                ],
                'message' => [
                    'type' => 'string',
                ],
            ],
        ];
        $data['definitions']['PaginationLinks'] = [
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
                ],
                'next' => [
                    'type' => 'string',
                    'format' => 'url',
                    'description' => 'Next page',
                ],
            ],
        ];
        $data = array_merge($baseData, $data);
        $definitionOverrides = [
            'Expense' => [
                'properties' => [
                    'total_cost' => [
                        'type' => 'number',
                        'description' => 'The total amount of the expense.',
                        'format' => 'float',
                    ],
                    'units' => [
                        'type' => 'integer',
                        'description' => 'The quantity of units to use in calculating the total_cost of the expense.',
                        'format' => 'int32',
                    ],
                ],
            ],
        ];
        $warnings = [];

        foreach ($definitionOverrides as $definition => $override) {
            if (isset($override['properties'])) {
                foreach ($override['properties'] as $propertyName => $propertyOverride) {
                    if (isset($data['definitions'][$definition])
                        && isset($data['definitions'][$definition]['properties'][$propertyName])) {
                        $warnings[] = sprintf(
                                'The property "%s" of the definition "%s" already exists and has been overriden.',
                                $propertyName,
                                $definition
                            );
                    }

                    $data['definitions'][$definition]['properties'][$propertyName] = $propertyOverride;
                }
            }
        }

        file_put_contents($this->target, Yaml::dump($data, 20, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return $warnings;
    }
}
