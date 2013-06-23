CDNMD5
==========

A helful library to allow you to create a unique version of every asset on
a CDN, but renaming the filename (on the CDN) to the md5sum hash of the file.

This means you always defeate browser cache when the file is changed, but also
always utilize browser cache when the file remains unchanged...

No more query string timestamps.

Justification
---------------

File Assets need to be stored on a CDN
but each "specific" version of the files need to be accessed,
and sometimes we are going forward in time, and backward in time,
all versions need to be available, uniquely

As such, the basic plan is:

on file creation/editing:
before you use this library, create the file...
then use this library to:

1. make a md5sum of the file ($md5hash)
2. store the md5sum of the file in a config file (commited to git)
3. make a copy of the file renamed to `"{$filename}_{$md5hash}.{$ext}"`
4. transfer the renamed copy to the CDN

At the time of rendering the file:

`Cdnmd5::url($filename) ==> http://domain/filename_md5hash.ext`

This function looks up the md5hash stored in the config file and returns
the URL to the CDN for the file...

If we are in development mode: `Configure::read('Cdnmd5.disabled') == true`

OR

If that md5hash doesn't exist for some reason (or is empty) we try to just
load the "real" file from the local repository.

Requirements:
-------------------

1. php5
2. php5-curl
3. CakePHP (could be decoupled with a little bit of work)

(CakePHP 1.3, CakePHP 2x versions available, switch branches)

NOTE: submodules added for API Libs, inside plugin/vendors

git://github.com/zeroasterisk/php-opencloud.git -> [plugindir]/vendors/php-opencloud

Installation:
-------------------

Put the plugin into the correct place in the CakePHP app:

**CakePHP 1.3**

```
cd repo
git submodule add git://github.com/zeroasterisk/cdnmd5 app/plugins/cdnmd5
cd app/plugins/cdnmd5
git checkout 1.3
cd ../../..
git submodule update --init --recursive
cp app/plugins/cdnmd5/config/cdnmd5.example.php app/config/cdnmd5.php
vim app/config/cdnmd5.php
```

Usage:
-----------------

After you create your CDNable assets (AssetCompress, Closure, Uglify, CssMin, etc)

```
App::Import('Lib', 'Cdnmd5.Cdnmd5');
Cdnmd5::process(APP . $fullPathToFile);
```

And how to render it in your Views

```
App::Import('Lib', 'Cdnmd5.Cdnmd5');
$this->Html->script(Cdnmd5::url($webrootRelativePathTofile));
```

Or with the simple helper:

```
$this->Cdnmd5->script($webrootRelativePathTofile);
```

About / License
----------------

Author: Alan Blount <alan@zeroasterisk.com>

License: MIT (see https://github.com/zeroasterisk/cdnmd5/LICENSE.txt)

(pull requests encouraged)

