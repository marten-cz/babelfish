<?php
/*
 * Copyright (c) 2010 Jan Smitka <jan@smitka.org>
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.

 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 *
 */

namespace Marten\Babelfish;

use Nette;


/**
 * Panel for Nette DebugBar, which enables you to translate strings
 * directly from your browser.
 *
 * @author Jan Smitka <jan@smitka.org>
 * @author Patrik Votocek <patrik@votocek.cz>
 * @author Vaclav Vrbka <gmvasek@php-info.cz>
 */
class Panel extends Nette\Object implements Nette\Diagnostics\IBarPanel
{

	const XHR_HEADER = 'X-Translation-Client';

	const SESSION_NAMESPACE = 'Babelfish-Panel';

	const LANGUAGE_KEY = 'X-Babelfish-Lang';

	const FILE_KEY = 'X-Babelfish-File';

	/* Layout constants */
	const LAYOUT_HORIZONTAL = 1;
	const LAYOUT_VERTICAL = 2;

	/** @var int TranslationPanel layout */
	protected $layout = self::LAYOUT_VERTICAL;

	/** @var int Height of the editor */
	protected $height = 410;

	/** @var \SystemContainer */
	protected $container;

	/** @var IEditable */
	protected $translator;


	public function __construct(\SystemContainer $container, IEditable $translator, $layout = NULL, $height = NULL)
	{
		$this->container = $container;
		$this->translator = $translator;

		if ($height !== NULL)
		{
			if (!is_numeric($height))
			{
				throw new \InvalidArgumentException('Panel height has to be a numeric value.');
			}
			$this->height = $height;
		}

		if ($layout !== NULL)
		{
			$this->layout = $layout;
			if ($height === NULL)
			{
				$this->height = 500;
			}
		}

		$this->processRequest();
	}


	/**
	 * Return's panel ID.
	 * @return string
	 */
	public function getId()
	{
		return 'translation-panel';
	}


	/**
	 * Returns the code for the panel tab.
	 * @return string
	 */
	public function getTab()
	{
		ob_start();
		require __DIR__ . '/tab.phtml';
		return ob_get_clean();
	}


	/**
	 * Returns the code for the panel
	 *
	 * @return string Panel HTML
	 */
	public function getPanel()
	{
		$translator = $this->translator;
		$files = array_keys($translator->getFiles());
		$strings = $translator->getStrings();

		$requests = $this->container->application->requests;
		$count = count($requests);
		$presenterName = ($count > 0) ? $requests[count($requests) - 1]->presenterName : NULL;
		$module = (!$presenterName) ? : strtolower(str_replace(':', '.', ltrim(substr($presenterName, 0, -(strlen(strrchr($presenterName, ':')))), ':')));
		$activeFile = (in_array($module, $files)) ? $module : $files[0];

		if ($this->container->session->isStarted())
		{
			$session = $this->container->session->getSection(static::SESSION_NAMESPACE);
			$untranslatedStack = isset($session['stack']) ? $session['stack'] : array();
			foreach ($strings as $string => $data)
			{
				if (!$data)
				{
					$untranslatedStack[$string] = FALSE;
				}
			}
			$session['stack'] = $untranslatedStack;

			foreach ($untranslatedStack as $string => $value)
			{
				if (!isset($strings[$string]))
				{
					$strings[$string] = FALSE;
				}
			}
		}

		ob_start();
		require __DIR__ . '/panel.phtml';
		return ob_get_clean();
	}


	/**
	 * Handles an incoming request and saves the data if necessary.
	 */
	private function processRequest()
	{
		try
		{
			$session = $this->container->session->getSection(self::SESSION_NAMESPACE);
		}
		catch (Nette\InvalidStateException $e)
		{
			$session = FALSE;
		}

		$request = $this->container->httpRequest;
		if ($request->isPost() && $request->isAjax() && $request->getHeader(self::XHR_HEADER))
		{
			$data = json_decode(file_get_contents('php://input'));
			$translator = $this->translator;

			if ($data)
			{
				if ($session)
				{
					$stack = isset($session['stack']) ? $session['stack'] : array();
				}

				$translator->lang = $data->{self::LANGUAGE_KEY};
				$file = $data->{self::FILE_KEY};
				unset($data->{self::LANGUAGE_KEY}, $data->{self::FILE_KEY});

				foreach ($data as $string => $value)
				{
					$translator->setTranslation($string, $value, $file);
					if ($session && isset($stack[$string]))
					{
						unset($stack[$string]);
					}
				}
				$translator->save($file);

				if ($session)
				{
					$session['stack'] = $stack;
				}
			}
		}
	}


	/**
	 * Return an ordinal number suffix
	 *
	 * @param string $count Count
	 *
	 * @return string
	 */
	protected function ordinalSuffix($count)
	{
		switch (substr($count, -1)) {
			case '1':
				return 'st';
			case '2':
				return 'nd';
			case '3':
				return 'rd';
			default:
				return 'th';
		}
	}


	/**
	 * Register this panel
	 *
	 * @param \SystemContainer $container Container instance
	 * @param \Marten\Babelfish\IEditable $translator Translator instance
	 * @param int $layout Layout type
	 * @param int $height Popup height
	 *
	 * @return NULL
	 */
	public static function register(\SystemContainer $container, IEditable $translator, $layout = NULL, $height = NULL)
	{
		Nette\Diagnostics\Debugger::$bar->addPanel(new static($container, $translator, $layout, $height));
	}


}
