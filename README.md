# Harvestapp swagger generator

Extracts documentation from the [documentation website](https://help.getharvest.com/api-v2/)
and generates a swagger.yaml file (OpenAPI 2.0).

## Usage

```sh
$ composer install
$ ./bin/extractor generate
```

Then check the `generated` directory, it should contain an OpenAPI 2.0 valid
file named `harvest-openapi.yaml`.

## I just need the OpenAPI file

You can get the generated file here:
[https://github.com/jolicode/harvest-openapi-generator/master/generated/harvest-openapi.yaml](https://github.com/jolicode/harvest-openapi-generator/master/generated/harvest-openapi.yaml)

## What can I do with this OpenAPI spec?

There are many tools to use an OpenAPI / Swagger specification:

 * API documentation generation
 * API client SDK generation, in many languages,
 * etc.

Please check out the [Swagger website](https://swagger.io/tools/open-source/open-source-integrations/),
which lists many useful tools and integrations.

## Troubleshooting

This extractor/generator uses several nasty tricks to extract Harvest API
properties... the process is not bulletproof and may break in case of
documentation change. Should you find a bug, incompleteness or problem with
the generated Swagger specification, please do not hesitate to
[open an issue](https://github.com/jolicode/harvest-openapi-generator/issues)
and share it with us.

## Further documentation

You can see the current and past versions using one of the following:

* the `git tag` command
* the [releases page on Github](https://github.com/jolicode/harvest-openapi-generator/releases)

And, finally, some [contribution instructions](CONTRIBUTING.md).

## License

This library is licensed under the MIT License - see the [LICENSE](LICENSE.md)
file for details.
