  * [Download](http://mediawiki-gsa-engine.googlecode.com/svn/trunk/SearchGSA.php) or [Checkout](http://code.google.com/p/mediawiki-gsa-engine/source/checkout) `SearchGSA.php`
  * Copy to `$MEDIAWIKI/extensions/SearchGSA.php`
  * Add the following to `LocalSettings.php`:
```
$wgGSA = 'http://10.2.10.10/search';
require_once("$IP/extensions/SearchGSA.php");
$wgSearchType       = "SearchGSA";
```