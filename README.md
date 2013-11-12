Babelfish
=========

Gettext translator for Nette framework based on Zend gettext
with tweaks and caching options.

How to wire it to Nette
-----------------------

Add these lines to your config file

	parameters:
		translator:
			defaultLanguage: en_US
			files:
				0 = 'core'
			cache.service = 'memcache'
			cache.level = 1
			panel = false

	services:
		translator:
			factory: Marten\Babelfish\Babelfish::getTranslator(@container, %translator%)
