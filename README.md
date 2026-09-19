# [DEPRECATED] Harvestapp swagger generator

> [!CAUTION]
> **This project is deprecated and no longer maintained.** There will be no
> further updates, and this repository is archived. The generated OpenAPI file
> is frozen and will progressively drift from Harvest's documentation. We no
> longer use Harvest and no longer recommend relying on this project.
> [Read why](#why-this-project-is-no-longer-maintained).

## Why this project is no longer maintained

JoliCode had been a Harvest customer for 14 years. We created this generator
to build and maintain [an open source PHP client for Harvest's API](https://github.com/jolicode/harvest-php-api),
as a contribution to the PHP ecosystem.

In early August 2026, we were informed that the price of our subscription
would be **multiplied by 12**, with only a few weeks' notice, in the middle of
the summer, and without any meaningful change in the features provided.

We consider that a vendor changing its pricing in such proportions, with such
short notice, is not a partner we can rely on in the long run. We have
therefore moved away from Harvest entirely, and have no reason left to
maintain this project. The
[Harvest PHP client](https://github.com/jolicode/harvest-php-api) is deprecated
for the same reasons.

What this means:

 * the OpenAPI specification will not follow future changes of the Harvest API;
 * no support: issues and pull requests are closed, the repository is read-only.

If you are a Harvest customer, we encourage you to evaluate alternatives. If
you still need this project, it remains available under the MIT license: feel
free to fork it.

## Legacy documentation

The following documentation is kept for reference only.

This project extracts documentation from the [documentation website](https://help.getharvest.com/api-v2/)
and generates an OpenAPI 3.0 file that can be used to further generate a client. You can see for example [the harvest-php-api library](https://github.com/jolicode/harvest-php-api), or [the Swagger editor loaded with this OpenApi file](https://editor.swagger.io/?url=https://raw.githubusercontent.com/jolicode/harvest-openapi-generator/master/generated/harvest-openapi.yaml).

### Usage

```sh
$ composer install
$ ./bin/extractor generate
```

Then check the `generated` directory, it should contain an OpenAPI 3.0 valid
file named `harvest-openapi.yaml`.

### I just need the OpenAPI file

You can get the last generated file here (it is not updated anymore):
[https://raw.githubusercontent.com/jolicode/harvest-openapi-generator/master/generated/harvest-openapi.yaml](https://raw.githubusercontent.com/jolicode/harvest-openapi-generator/master/generated/harvest-openapi.yaml)

### What can I do with this OpenAPI spec?

There are many tools to use an OpenAPI / Swagger specification:

 * API documentation generation
 * API client SDK generation, in many languages,
 * etc.

Please check out the [Swagger website](https://swagger.io/tools/open-source/open-source-integrations/),
which lists many useful tools and integrations.

### Overriding definitions

This extractor/generator uses several nasty tricks to extract Harvest API
properties... the process is not bulletproof and may break in case of
documentation change.

If the [documentation](https://help.getharvest.com/api-v2/) is incomplete, you
may want to override a definition. See the
`JoliCode\Extractor\Dumper\Dumper:dump()` method.

### Further documentation

You can see the past versions using one of the following:

* the `git tag` command
* the [releases page on Github](https://github.com/jolicode/harvest-openapi-generator/releases)

## License

This library is licensed under the MIT License - see the [LICENSE](LICENSE.md)
file for details.
