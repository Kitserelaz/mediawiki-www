# GoogleForms
This is an extension that allows you to embed Google Forms onto wiki pages via
iframes.

## Usage
Call the `<gforms />` or `<googleforms />` tag with the following attributes:
* `id` (required): The ID of the form. This is the "XYZ" part in "https://docs.google.com/forms/d/e/XYZ/viewform".
* `width` (optional): The width of the iframe in [CSS pixels], or a percentage.
* `height` (optional): The height of the iframe in [CSS pixels], or a
  percentage.
* `style` (optional): Inline CSS for the iframe.
* `class` (optional): Classes for the iframe.

[CSS pixels]: https://developer.mozilla.org/en-US/docs/Glossary/CSS_pixel

## Examples
```
<gforms id="1FAIpQLScPoWuT5Udr3gGi6YZoreUIEUY8sb1xCTtfidZmFr1mlDqBSQ" />
<gforms id="1FAIpQLScPoWuT5Udr3gGi6YZoreUIEUY8sb1xCTtfidZmFr1mlDqBSQ" width="100%" height="500" />
<gforms id="1FAIpQLScPoWuT5Udr3gGi6YZoreUIEUY8sb1xCTtfidZmFr1mlDqBSQ" width="100%" class="google-form" style="height: 50em" />
```

## Credits
* [BoilerPlate]: Example extension that this extension is based off of

[BoilerPlate]: https://www.mediawiki.org/wiki/Extension:BoilerPlate

## See also
* [Extension:GoogleDocTag]: Embed Google Docs onto wiki pages
* [Extension:GoogleDocs4MW]: Embed Google Spreadsheets onto wiki pages

[Extension:GoogleDocTag]: https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:GoogleDocTag
[Extension:GoogleDocs4MW]: https://www.mediawiki.org/wiki/Special:MyLanguage/Extension:GoogleDocs4MW
