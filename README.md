(WARNING: this is an alpha version)

Extension:DownloadBook.

This extension allows Extension:Collection to generate PDF files locally.
For example:
```php
wfLoadExtension( 'DownloadBook' );

wfLoadExtension( 'Collection' );
$wgCollectionDisableDownloadSection = false;
$wgCollectionMWServeURL = 'https://url-of-your-mediawiki-site/wiki/Special:DownloadBook';
```

By default it uses Pandoc (third-party command line utility) to do the conversion,
and supports only PDF.
Modify `$wgDownloadBookConvertCommand` (see `extension.json`) to use another tool or more formats.
