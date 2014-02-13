<?php

namespace Marten\Babelfish;

require __DIR__ . '/shortcuts.php';

use Nette,
	Nette\Utils\Strings;

/**
 * Nette gettext translator
 * 
 * This solution is partitionaly based on Zend_Translate_Adapter_Gettext
 * (c) Zend Technologies USA Inc. (http://www.zend.com), new BSD license
 *
 * @author Martin Malek
 * @example http://addons.nettephp.com/gettext-translator
 * @version 0.5
 */
class Babelfish extends Nette\Object implements IEditable
{

	const SESSION_NAMESPACE = 'Babelfish-Gettext';

	const CACHE_DISABLE = 0;
	const CACHE_FILE = 1;
	const CACHE_TRANSLATIONS = 2;
	const CACHE_ALL = 3;

	/**
	 * @var string MO file explode character
	 */
	protected $moExplodeChar = 0x00;

	/**
	 * @var array List of files
	 */
	protected $files = array();

	/**
	 * @var string Language
	 */
	protected $lang = "en_US";

	/**
	 * Pole s hodnotama metadat z prekladovych souboru
	 *
	 * @var array Metadata
	 */
	private $metadata;

	/**
	 * Jako hodnota je ulozeno pole s klicema:
	 *
	 * original:
	 * Bud se jedna o string (preklad nema pluralovou formu), nebo o array, kde
	 * pod indexem 0 je singular a pod indexem 1 plural.
	 *
	 * translation:
	 * Obsahuje bud jeden preklad pro singular, nebo pole s vice preklady pro
	 * jednotlive pluralove formy. Jejich pocet by mel byt stejny jako je
	 * nadefinovano v Plural-Forms.
	 *
	 * file:
	 * Identifikator souboru ve kterem preklad je
	 *
	 * @var array<string|array> Pole s preklady
	 */
	protected $dictionary = array();

	/**
	 * @var bool Dictionaries were loaded
	 */
	private $loaded = FALSE;

	/**
	 * @var bool Cache for the translation files
	 */
	private $cacheMode = self::CACHE_DISABLE;

	/**
	 * @var \Nette\DI\IContainer Nette container
	 */
	protected $container;

	/**
	 * @var \Nette\Http\Session Session
	 */
	protected $session;

	/**
	 * @var \Nette\Caching\Cache Caching object
	 */
	protected $cache;

	/**
	 * @var array Cached translations
	 */
	protected $cacheTranslations = array();

	/**
	 * @var callback Callback for translation
	 */
	public $translateCallback;

	/**
	 * @var bool Object is frozen
	 */
	private $frozen = FALSE;

	/**
	 * @var array Plural forms cache
	 */
	protected static $forms = array();

	/**
	 * @var array Events when translation string is missing
	 */
	public $onTranslateMissing = array();


	/**
	 * Get metadata information
	 *
	 * @return array Metadata information
	 */
	public function getMetadata()
	{
		return $this->metadata;
	}


	/**
	 * Enable cache
	 *
	 * Example:
	 * <code>
	 * $this->enableCache(new Nette\Caching\Cache(new \Nette\Caching\Storages\FileStorage('/tmp/', new \Nette\Caching\Storages\FileJournal('/tmp/')), static::SESSION_NAMESPACE));
	 *
	 * $this->enableCache($container->getService('nodusCacheStorage'), self::CACHE_ALL);
	 * </code>
	 *
	 * @param \Nette\Caching\IStorage|NULL $cache Cache object, memory storage will be used by default.
	 *
	 * @return void
	 */
	public function enableCache($cache = NULL, $cacheMode = self::CACHE_FILE)
	{
		$cache = $cache ? $cache : new Nette\Caching\Cache(new \Nette\Caching\Storages\MemoryStorage(), static::SESSION_NAMESPACE);

		$this->cache = $cache;
		$this->cacheMode = $cacheMode;
	}


	/**
	 * Disable cache
	 *
	 * @return void
	 */
	public function disableCache()
	{
		$this->cacheMode = self::CACHE_DISABLE;
	}


	/**
	 * Construct
	 *
	 * Create the Babelfish translator object. Path to the translation files,
	 * default language and other default values are taken from the context
	 * (first parameter). With second optional parameter you can specify the
	 * list of files to be loaded to the translator. If the array is empty
	 * no files will be added. You can add more files later with {@see addFile()}.
	 * With the third parameter you can change the language for the translations.
	 *
	 * @param \Nette\DI\IContainer $container Container
	 * @param array $files List of files with translations
	 * @param string $lang Language
	 *
	 * @return NULL
	 */
	public function __construct(Nette\DI\IContainer $container, array $files = NULL, $lang = NULL)
	{
		if(empty($lang) && !empty($container->params['lang']))
		{
			$lang = $container->params['lang'];
		}

		if (!empty($lang))
		{
			$this->lang = $lang;
		}

		$this->container = $container;
		$this->session = $storage = $container->session->getSection(static::SESSION_NAMESPACE);

		$this->moExplodeChar = iconv('UTF-32BE', 'UTF-8' . '//IGNORE', pack('N', 0x00));

		$this->registerTranslateCallback(callback($this, 'translateFast'));

		if (is_array($files) && count($files) > 0)
		{
			foreach($files as $identifier => $dir)
			{
				$this->addFile($dir, $identifier);
			}
		}

		$this->onTranslateMissing[] = callback($this, 'foundNewString');
	}


	/**
	 * Register callback method
	 *
	 * Set the callback for method {@see translate()}
	 * The first call with load all of the dictionaries,
	 * second call will only call the translation.
	 *
	 * @param callback|array $translateMethod Callback method
	 *
	 * @return NULL
	 */
	private function registerTranslateCallback($translateMethod)
	{
		$me = $this;

		$this->translateCallback = function($message, $form = 1) use ($me, $translateMethod) {
			$me->loadDictonary();
			$me->translateCallback = function($message, $form = 1) use ($me, $translateMethod) {
				$args = func_get_args();
				return call_user_func_array($translateMethod, $args);
			};
			$args = func_get_args();
			return call_user_func_array($translateMethod, $args);
		};
	}


	/**
	 * Adds a file to parse
	 *
	 * @param string $dir Directory
	 * @param string $identifier Identifier
	 *
	 * @throws \Nette\InvalidStateException If the directory does not exists or the file is already registered
	 * @return NULL
	 */
	public function addFile($dir, $identifier)
	{
		if(strpos($dir, '%') !== FALSE)
		{
			$dir = $this->container->expand($dir);
		}

		if(isset($this->files[$identifier]))
		{
			throw new \InvalidArgumentException("Language file identified '$identifier' is already registered.");
		}

		if(!is_dir($dir))
		{
			throw new \InvalidArgumentException("Directory '$dir' doesn't exist.");
		}

		$this->files[$identifier] = $dir;
	}


	/**
	 * Load data from the files
	 *
	 * @throws \Nette\InvalidStateException When there is not language file
	 * @return NULL
	 */
	public function loadDictonary()
	{
		if ($this->loaded)
		{
			return;
		}

		if (empty($this->files))
		{
			throw new \Nette\InvalidStateException("Language file(s) must be defined.");
		}

		$filesHash = md5(serialize($this->files));
		$cache = $this->cache;
		$fromCache = FALSE;

		if ($this->cacheMode && $this->cacheMode & self::CACHE_FILE)
		{
			$cacheDictionaryValue = $cache->read('dictionary-'.$this->lang.$filesHash);
			$cacheMetadataValue = $cache->read('metadata-'.$this->lang.$filesHash);
			if (!empty($cacheDictionaryValue) && !empty($cacheMetadataValue))
			{
				$this->dictionary = $cacheDictionaryValue;
				$this->metadata = $cacheMetadataValue;
				$fromCache = TRUE;
			}
		}

		if ($fromCache == FALSE)
		{
			$files = array();
			foreach ($this->files as $identifier => $dir)
			{
				$path = "$dir/{$this->lang}/{$identifier}.mo";
				if (file_exists($path))
				{
					$this->parseMoFile($path, $identifier);
					$files[] = $path;
				}
			}

			if ($this->cacheMode && $this->cacheMode & self::CACHE_FILE)
			{
				$cache->write('dictionary-'.$this->lang.$filesHash, $this->dictionary, array(\Nette\Caching\Cache::EXPIRATION => 3600));
				$cache->write('metadata-'.$this->lang.$filesHash, $this->metadata, array(\Nette\Caching\Cache::EXPIRATION => 3600));
			}
		}
		$this->loaded = TRUE;
	}


	/**
	 * Parse dictionary file
	 *
	 * Parse the MO file.
	 *
	 * @param string $file File path
	 *
	 * @throws \InvalidArgumentException
	 * @return NULL
	 */
	protected function parseMoFile($file, $identifier)
	{
		$f = fopen($file, 'rb');
		if (filesize($file) < 10)
		{
			throw new \InvalidArgumentException('\'' . $file . '\' is not a gettext file.');
		}

		$endian = FALSE;
		$read = function($bytes) use ($f, $endian)
		{
			$data = fread($f, 4 * $bytes);
			return $endian === FALSE ? unpack('V' . $bytes, $data) : unpack('N' . $bytes, $data);
		};

		$input = $read(1);

		//$checkEndian = Strings::lower(substr(dechex($input[1]), -8));
		$checkEndian = strtolower(substr(dechex($input[1]), -8));

		if ($checkEndian == "950412de")
		{
			$endian = FALSE;
		}
		else if ($checkEndian == "de120495")
		{
			$endian = TRUE;
		}
		else
		{
			fclose($f);
			throw new \InvalidArgumentException('\'' . $file . '\' is not a gettext file.');
		}

		$input = $read(1);

		$input = $read(1);
		$total = $input[1];

		$input = $read(1);
		$originalOffset = $input[1];

		$input = $read(1);
		$translationOffset = $input[1];

		fseek($f, $originalOffset);
		$orignalTmp = $read(2 * $total);
		fseek($f, $translationOffset);
		$translationTmp = $read(2 * $total);

		$moExplodeChar = $this->moExplodeChar;

		for ($i = 0; $i < $total; ++$i)
		{
			$charPosition = $i * 2;
			if ($orignalTmp[$charPosition + 1] != 0)
			{
				fseek($f, $orignalTmp[$charPosition + 2]);
				$original = @fread($f, $orignalTmp[$charPosition + 1]);
			}
			else
			{
				$original = "";
			}

			if ($translationTmp[$charPosition + 1] != 0)
			{
				fseek($f, $translationTmp[$charPosition + 2]);
				$translation = fread($f, $translationTmp[$charPosition + 1]);
				if ($original === "")
				{
					$this->parseMetadata($translation, $identifier);
					continue;
				}

				$original = explode($moExplodeChar, $original);
				$translation = explode($moExplodeChar, $translation);
				$dictionaryKey = is_array($original) ? $original[0] : $original;
				$this->dictionary[$dictionaryKey] = array(
					'original' => $original,
					'translation' => $translation,
					'file' => $identifier,
				);
			}
		}

		fclose($f);
	}


	/**
	 * Metadata parser
	 *
	 * @param string $input Metadata lide
	 * @param string $identifier File identifier
	 *
	 * @return NULL
	 */
	private function parseMetadata($input, $identifier)
	{
		$input = trim($input);

		$input = explode("\n", $input);
		foreach ($input as $metadata)
		{
			$pattern = ': ';
			$tmp = explode($pattern, $metadata);
			$this->metadata[$identifier][trim($tmp[0])] = count($tmp) > 2 ? ltrim(strstr($metadata, $pattern), $pattern) : @$tmp[1];
		}
	}


	/**
	 * Translates the given string
	 *
	 * Call the callback, by default it will call the parse with first call
	 * and every other call will just execute the method.
	 *
	 * @param string $message String to translate
	 * @param int $form plural form (positive number)
	 *
	 * @return string Translated string
	 */
	public function translate($message, $form = 1)
	{
		if ($this->cacheMode && $this->cacheMode & self::CACHE_TRANSLATIONS)
		{
			$cache = $this->cache;
			$cacheHash = 'translations_' . $this->lang;
			$cacheTranslationHash = md5(serialize(func_get_args()));

			if (empty($this->cacheTranslations))
			{
				$cacheValue = $cache->read($cacheHash);
				if (!empty($cacheValue))
				{
					if (is_array($cacheValue))
					{
						$this->cacheTranslations = $cacheValue;
					}
					else
					{
						// @todo Log error 'Translator translations are not array.'
					}
				}
			}

			if (!empty($this->cacheTranslations))
			{
				if (!empty($this->cacheTranslations[$cacheTranslationHash]))
				{
					return $this->cacheTranslations[$cacheTranslationHash];
				}
			}
		}

		$args = func_get_args();
		$translatedMessage = call_user_func_array($this->translateCallback, $args);

		if ($this->cacheMode && $this->cacheMode & self::CACHE_TRANSLATIONS)
		{
			$this->cacheTranslations[$cacheTranslationHash] = $translatedMessage;
			$cache->write($cacheHash, $this->cacheTranslations, array(\Nette\Caching\Cache::EXPIRATION => 3600));
		}

		return $translatedMessage;
	}


	/**
	 * Translate the string
	 *
	 * Search for the string in language files.
	 *
	 * @param string $message String to translate
	 * @param int $form plural form (positive number)
	 *
	 * @return string Translated string
	 */
	public function translateFast($message, $form = 1)
	{
		$files = array_keys($this->files);

		$message = (string) $message;
		$message_plural = NULL;
		if (is_array($form))
		{
			$message_plural = current($form);
			$form = (int) end($form);
		}

		if (!is_int($form))
		{
			$form = 1;
		}

		if (isset($this->dictionary[$message]))
		{
			if(!empty($this->metadata[$files[0]]['Plural-Forms']))
			{
				// Preg_replace cache
				if (
					empty(self::$forms[$this->metadata[$files[0]]['Plural-Forms']][$form])
					|| !($tmp = self::$forms[$this->metadata[$files[0]]['Plural-Forms']][$form])
				)
				{
					$tmp = preg_replace('/([a-z]+)/', '$$1', "n=$form;".$this->metadata[$files[0]]['Plural-Forms']);
					self::$forms[$this->metadata[$files[0]]['Plural-Forms']][$form] = $tmp;
				}
				eval($tmp);
			}

			// @todo refactor
			$message = @$this->dictionary[$message]['translation'] ?: $message;
			if (!empty($message) && isset($plural) && $plural !== NULL)
			{
				$message = @(is_array($message) && isset($message[$plural])) ? $message[$plural] : $message;
			}
		}
		else
		{
			$this->onTranslateMissing($this->lang, $message, $message_plural, $form);
		}

		if (is_array($message))
		{
			$message = current($message);
		}

		$args = func_get_args();
		$argsCount = count($args);
		if ($argsCount > 1)
		{
			array_shift($args);
			if (is_array(current($args)) || current($args) === NULL)
			{
				array_shift($args);
			}

			if (count($args) == 1 && is_array(current($args)))
			{
				$args = current($args);
			}

			if (count($args) > 0 && $args != NULL)
			{
				$message = vsprintf($message, $args);
			}
		}

		return $message;
	}

	/**
	 * Translates the given string
	 *
	 * @param string $message
	 * @param int $form plural form (positive number)
	 *
	 * @return string
	 */
	public function translateOld($message, $form = 1)
	{
		$this->loadDictonary();
		$files = array_keys($this->files);

		$message = (string) $message;
		$message_plural = NULL;
		if (is_array($form) && $form !== NULL)
		{
			$message_plural = current($form);
			$form = (int) end($form);
		}
		if (!is_int($form) || $form === NULL)
		{
			$form = 1;
		}

		if (!empty($message) && isset($this->dictionary[$message]))
		{
			if(!empty($this->metadata[$files[0]]['Plural-Forms']))
			{
				$tmp = preg_replace('/([a-z]+)/', '$$1', "n=$form;".$this->metadata[$files[0]]['Plural-Forms']);
				eval($tmp);
			}


			$message = $this->dictionary[$message]['translation'];
			if (!empty($message))
			{
				$message = @(is_array($message) && $plural !== NULL && isset($message[$plural])) ? $message[$plural] : $message;
			}
		}
		else
		{
			if (!$this->container->httpResponse->isSent() || $this->container->session->isStarted())
			{
				$space = $this->session;
				if (!isset($space->newStrings[$this->lang]))
				{
					$space->newStrings[$this->lang] = array();
				}
				$space->newStrings[$this->lang][$message] = empty($message_plural) ? array($message) : array($message, $message_plural);
			}
			if ($form > 1 && !empty($message_plural))
			{
				$message = $message_plural;
			}
		}

		if (is_array($message))
			$message = current($message);

		$args = func_get_args();
		if (count($args) > 1)
		{
			array_shift($args);
			if (is_array(current($args)) || current($args) === NULL)
			{
				array_shift($args);
			}

			if (count($args) == 1 && is_array(current($args)))
			{
				$args = current($args);
			}

			$message = str_replace(array("%label", "%name", "%value"), array("#label", "#name", "#value"), $message);
			if (count($args) > 0 && $args != NULL)
			{
				$message = vsprintf($message, $args);
			}
			$message = str_replace(array("#label", "#name", "#value"), array("%label", "%name", "%value"), $message);
		}

		return $message;
	}


	/**
	 * Get count of plural forms
	 *
	 * @return int
	 */
	public function getVariantsCount($file = NULL)
	{
		$this->loadDictonary();

		if (!$file)
		{
			$files = array_keys($this->files);
			$file = $files[0];
		}

		if (isset($this->metadata[$file]['Plural-Forms']))
		{
			$variants = (int) substr($this->metadata[$file]['Plural-Forms'], 9, 1);
			return $variants > 0 ? $variants : 1;
		}

		return 1;
	}


	/**
	 * Get translations strings
	 *
	 * Get list of strings for translation. Used by translator Panel.
	 *
	 * @see Panel
	 *
	 * @return array
	 */
	public function getStrings($file = NULL)
	{
		$this->loadDictonary();

		$newStrings = array();
		$result = array();

		$storage = $this->session;
		if (isset($storage->newStrings[$this->lang]))
		{
			foreach (array_keys($storage->newStrings[$this->lang]) as $original)
			{
				if (trim($original) != '')
				{
					$newStrings[$original] = FALSE;
				}
			}
		}

		foreach ($this->dictionary as $original => $data)
		{
			if (trim($original) != '')
			{
				if($file && $data['file'] === $file)
				{
					$result[$original] = $data['translation'];
				}
				else
				{
					$result[$data['file']][$original] = $data['translation'];
				}
			}
		}

		if($file)
		{
			return array_merge($newStrings, $result);
		}
		else
		{
			foreach($this->getFiles() as $identifier => $path)
			{
				if(!isset($result[$identifier]))
				{
					$result[$identifier] = array();
				}
			}

			return array('newStrings' => $newStrings) + $result;
		}
	}


	/**
	 * Get loaded files
	 *
	 * @return array
	 */
	public function getFiles()
	{
		$this->loadDictonary();

		return $this->files;
	}


	/**
	 * Set translation string(s)
	 *
	 * @param string|array $message original string(s)
	 * @param string|array $string translation string(s)
	 * @param string $file File
	 *
	 * @return void
	 */
	public function setTranslation($message, $string, $file)
	{
		$this->loadDictonary();

		$space = $this->session;
		if (isset($space->newStrings[$this->lang]) && array_key_exists($message, $space->newStrings[$this->lang]))
		{
			$message = $space->newStrings[$this->lang][$message];
		}

		$this->dictionary[is_array($message) ? $message[0] : $message] = array(
			'original' => (array) $message,
			'translation' => (array) $string,
			'file' => $file,
		);
	}


	/**
	 * Save dictionary
	 *
	 * Save the updated dictionary file.
	 *
	 * @param string $file Filename
	 *
	 * @throws Nette\InvalidStateException When the file was not loaded
	 * @return void
	 */
	public function save($file)
	{
		if(!$this->loaded)
		{
			throw new Nette\InvalidStateException("Nothing to save, translations are not loaded.");
		}

		if(!isset($this->files[$file]))
		{
			throw new \InvalidArgumentException("Gettext file identified as '$file' does not exist.");
		}

		$dir = $this->files[$file];
		$path = "$dir/{$this->lang}/{$file}";

		$this->buildMOFile("$path.mo", $file);
		$this->buildPOFile("$path.po", $file);

		$storage = $this->session;
		if (isset($storage->newStrings[$this->lang]))
		{
			unset($storage->newStrings[$this->lang]);
		}
		if ($this->cacheMode)
		{
			$cache = $this->cache
				->clean(array(\Nette\Caching\Cache::TAGS => 'dictionary-'.$this->lang));
		}
	}


	/**
	 * Generate gettext metadata array
	 *
	 * @return array
	 */
	private function generateMetadata($identifier)
	{
		$keys = array(
			'Project-Id-Version' => NULL,
			'Report-Msgid-Bugs-To' => NULL,
			'POT-Creation-Date' => NULL,
			'Last-Translator' => NULL,
			'Language-Team' => NULL,
			'Plural-Forms' => NULL,
			'X-Poedit-Language' => NULL,
			'X-Poedit-Country' => NULL,
			'X-Poedit-KeywordsList' => NULL
		);

		$result = array();

		// defaults
		$result[] = 'PO-Revision-Date: ' . date("Y-m-d H:iO");
		$result[] = 'Content-Type: text/plain; charset=UTF-8';
		$result[] = 'MIME-Version: 1.0';
		$result[] = 'Content-Transfer-Encoding: 8bit';
		$result[] = 'X-Poedit-SourceCharset: utf-8';
		$result[] = 'Project-Id-Version: 1';
		$result[] = 'Language-Team: ';
		$result[] = 'Last-Translator: ';

		foreach($keys as $key => $default)
		{
			if(!empty($this->metadata[$identifier][$key]))
			{
				$result[] = "{$key}: " . $this->metadata[$identifier][$key];
			}
		}

		return $result;
	}


	/**
	 * Build gettext MO file
	 *
	 * @param string $file File
	 * @param string $identifier File identifier
	 *
	 * @return void
	 */
	private function buildPOFile($file, $identifier)
	{
		$po = "# Gettext keys exported by Babelfish and Translation Panel\n"
			."# Created: ".date('Y-m-d H:i:s')."\n".'msgid ""'."\n".'msgstr ""'."\n";
		$po .= '"'.implode('\n"'."\n".'"', $this->generateMetadata($identifier)).'\n"'."\n\n\n";
		foreach ($this->dictionary as $message => $data)
		{
			if($data['file'] !== $identifier)
			{
				continue;
			}

			$po .= 'msgid "'.str_replace(array('"', "'"), array('\"', "\\'"), $message).'"'."\n";
			if (is_array($data['original']) && count($data['original']) > 1)
			{
				$po .= 'msgid_plural "'.str_replace(array('"', "'"), array('\"', "\\'"), end($data['original'])).'"'."\n";
			}
			if (!is_array($data['translation']))
			{
				$po .= 'msgstr "'.str_replace(array('"', "'"), array('\"', "\\'"), $data['translation']).'"'."\n";
			}
			else if (count($data['translation']) < 2)
			{
				$po .= 'msgstr "'.str_replace(array('"', "'"), array('\"', "\\'"), current($data['translation'])).'"'."\n";
			}
			else
			{
				$i = 0;
				foreach ($data['translation'] as $string)
				{
					$po .= 'msgstr['.$i.'] "'.str_replace(array('"', "'"), array('\"', "\\'"), $string).'"'."\n";
					$i++;
				}
			}
			$po .= "\n";
		}

		$storage = $this->session;
		if (isset($storage->newStrings[$this->lang]))
		{
			foreach ($storage->newStrings[$this->lang] as $original)
			{
				if (trim(current($original)) != "" && !\array_key_exists(current($original), $this->dictionary))
				{
					$po .= 'msgid "'.str_replace(array('"', "'"), array('\"', "\\'"), current($original)).'"'."\n";
					if (count($original) > 1)
					{
						$po .= 'msgid_plural "'.str_replace(array('"', "'"), array('\"', "\\'"), end($original)).'"'."\n";
					}
					$po .= "\n";
				}
			}
		}

		file_put_contents($file, $po);
	}


	/**
	 * Build gettext MO file
	 *
	 * @param string $file
	 *
	 * @return void
	 */
	private function buildMOFile($file, $identifier)
	{
		$dictionary = array_filter($this->dictionary, function($data) use($identifier) {
			return $data['file'] === $identifier;
		});

		ksort($dictionary);

		$metadata = implode("\n", $this->generateMetadata($identifier));
		$items = count($dictionary) + 1;
		$ids = Strings::chr(0x00);
		$strings = $metadata.Strings::chr(0x00);
		$idsOffsets = array(0, 28 + $items * 16);
		$stringsOffsets = array(array(0, strlen($metadata)));

		foreach ($dictionary as $key => $value)
		{
			$id = $key;
			if (is_array($value['original']) && count($value['original']) > 1)
			{
				$id .= Strings::chr(0x00).end($value['original']);
			}

			$string = implode(Strings::chr(0x00), $value['translation']);
			$idsOffsets[] = strlen($id);
			$idsOffsets[] = strlen($ids) + 28 + $items * 16;
			$stringsOffsets[] = array(strlen($strings), strlen($string));
			$ids .= $id.Strings::chr(0x00);
			$strings .= $string.Strings::chr(0x00);
		}

		$valuesOffsets = array();
		foreach ($stringsOffsets as $offset)
		{
			list ($all, $one) = $offset;
			$valuesOffsets[] = $one;
			$valuesOffsets[] = $all + strlen($ids) + 28 + $items * 16;
		}
		$offsets= array_merge($idsOffsets, $valuesOffsets);

		$mo = pack('Iiiiiii', 0x950412de, 0, $items, 28, 28 + $items * 8, 0, 28 + $items * 16);
		foreach ($offsets as $offset)
		{
			$mo .= pack('i', $offset);
		}

		file_put_contents($file, $mo.$ids.$strings);
	}


	/**
	 * Returns current language
	 *
	 * @return string Current language
	 */
	public function getLang()
	{
		return $this->lang;
	}


	/**
	 * Sets a new language
	 *
	 * @param string $lang Set the language
	 */
	public function setLang($lang)
	{
		if($this->lang === $lang)
		{
			return;
		}

		$this->lang = $lang;
		$this->dictionary = array();
		$this->loaded = FALSE;
		self::$forms = array();
		$this->metadata = array();
		$this->loadDictonary();
	}


	/**
	 * Translator factory
	 *
	 * Inject the translator to the Nette factory.
	 *
	 * @param \Nette\DI\IContainer $container
	 * @param array|Nette\ArrayHash $options
	 *
	 * @return \Marten\Babelfish\Babelfish Babelfish instance
	 */
	public static function getTranslator(Nette\DI\IContainer $container, $options = NULL)
	{
		$lang = $options['defaultLanguage'];
		if(!empty($options['languages']) && is_array($options['languages']))
		{
			if(!empty($_SERVER['HTTP_HOST'])  && !empty($_SERVER['REQUEST_URI']))
			{
				$domain = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
			}
			else
			{
				$domain = 'test';
			}
			foreach($options['languages'] as $regexp => $mapLang)
			{
				if(preg_match($regexp, $domain))
				{
					$lang = $mapLang;
				}
			}
		}

		$translator = new static($container, array(), $lang);

		if(!empty($options['files']) && is_array($options['files']))
		{
			foreach($options['files'] as $file)
			{
				$translator->addFile('%appDir%/locale', $file);
			}
		}

		if (!empty($options['cache']) && !empty($options['cache']['service']) && !empty($options['cache']['level']))
		{
			$translator->enableCache($container->{$options['cache']['service']}, $options['cache']['level']);
		}

		if (isset($options['panel']) && $options['panel'] === TRUE)
		{
			Panel::register($container, $translator);
			DebugPanel::register($container, $translator);
		}
		return $translator;
	}


	/**
	 * Event when the translator found untranslated string
	 *
	 * @param string $lang Language
	 * @param string $message Message
	 * @param string|NULL $message_plural Message plural form
	 * @param int $form Number of items
	 */
	public function foundNewString($lang, $message, $message_plural = NULL, $form = 1)
	{
		if (!$this->container->httpResponse->isSent() || $this->container->session->isStarted())
		{
			$space = $this->session;
			if (!isset($space->newStrings[$lang]))
			{
				$space->newStrings[$lang] = array();
			}
			$space->newStrings[$lang][$message] = empty($message_plural) ? array($message) : array($message, $message_plural);
		}
		if ($form > 1 && !empty($message_plural))
		{
			$message = $message_plural;
		}
	}


}
