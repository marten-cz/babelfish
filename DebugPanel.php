<?php

namespace Marten\Babelfish;

use Nette;
use Tracy;

/**
 * Panel for Nette DebugBar, which enables debugging of the translator.
 */
class DebugPanel extends Nette\Object implements Nette\Diagnostics\IBarPanel
{

	/* Layout constants */
	const LAYOUT_HORIZONTAL = 1;
	const LAYOUT_VERTICAL = 2;

	/**
	 * @var int TranslationPanel layout
	 */
	protected $layout = self::LAYOUT_VERTICAL;

	/**
	 * @var int Height of the editor
	 */
	protected $height = 410;

	/**
	 * @var \SystemContainer
	 */
	protected $container;

	/**
	 * @var IEditable
	 */
	protected $translator;

	/**
	 * @var array Missing translations
	 */
	protected $missingStrings = array();


	/**
	 * Construct the debug panel
	 *
	 * @param  $container Nette container
	 * @param IEditable $translator Translator object
	 * @param <type> $layout
	 * @param <type> $height
	 */
	public function __construct(\SystemContainer $container, IEditable $translator, $layout = NULL, $height = NULL)
	{
		$this->container = $container;
		$this->translator = $translator;

		$translator->onTranslateMissing[] = callback($this, 'foundNewString');

		if ($height !== NULL) {
			if (!is_numeric($height))
				throw new \InvalidArgumentException('Panel height has to be a numeric value.');
			$this->height = $height;
		}

		if ($layout !== NULL) {
			$this->layout = $layout;
			if ($height === NULL)
				$this->height = 500;
		}
	}


	/**
	 * Return's panel ID
	 *
	 * @return string
	 */
	public function getId()
	{
		return 'translation-debugpanel';
	}


	/**
	 * Returns the code for the panel tab
	 *
	 * @return string
	 */
	public function getTab()
	{
		ob_start();
		echo '<span><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABmJLR0QA/wD/AP+gvaeTAAAACXBIWXMAAABIAAAASABGyWs+AAAACXZwQWcAAAAQAAAAEABcxq3DAAADO0lEQVQ4y32TW0xbBQCGv3OhPVB6oYArpVAYShxrp9vAMTMQFJW4TU1m8MXEqDG6y5OJidNEI4m+uARFjQ9TY4y67BJn4gPLEiVGpxtMMxjSMV0LrFuRdm2hl9PTnp7jg9nDItn//v1P3ydwm5mjgIQMiMJ+il9vtjt8fqljMbViTqbMSX8VhrAmeBRoQjAmCagp+mPptqjc/GDQtq7pofTF8eXpM+NvPNAozX05X+aWA/MfyBwFi4tadVV60TCa9wk1fQ6l9RGsdT5n5sqFy6GTnwx5fP6pkWNjjM6CfBM2TgEhLGUHfWmt9RWlqW/A7uuXKhxeMAWKSxEiPxz5rvvwn1PDLQKjC/9xEkDmW7g240Uv5p9G6frMeffzQVvjZlGSDSivQGaV3N8zlGzFhuiPw6Ghg0+EHdN/cDoK8j4P2Kp8rKjJrTbl/rfdbQNuyeGGSicU5qF0A1QwtTgNO4Lrs7HcS4Odn447POgf7qBCen03NqGielBqffj9BWWgo0bJUaktgF4AdyOUQ2CmMeJJTGuWzOL8RPfGZm33Y1v3Vmhxn3jnJmG//177V7XtnntWTRlTTIF2HVIXoBSDyiS4oliq06TnzupG0dUVePy54y5/R79g6j/JJd08UTTCvfXOEzt3bfMipg2IAtihNA3uWVBWEUkjJpyyr6dnoyivkF48d6T/i9HLcsN9hK8vlPb6vMkPqmvVJ0lnBPIiCB4oz4IzAQUDSmD3t1NRq1E0ljRVjk9FvhlF/OhRcG+Srsbm1ZezV6yfG9n+MooXjCg4lkEyIC5SNlyFTGnpF702pQktXpSWDYbsDyD9DBw6bHKgc0suPlP+1bF+sNtS39WCVYNWEVQTIi0UstaZ8PnwM9Z69Zqs1LXrGctfvt6dk9JNke64FGNoZE8+del3papO2SXY6jB1P4K6HT0vaivR0DvtB7tPn9xz/myN1/59YTkR/bjzvcQtKkePByiqerBxS9uYxWVrzEbCIXKrF0v55PTib4kRq538hjdvU9/Eu+s4pCDemBx8thg7MJ+Ze+rcmbc8da+CMNazNvO/GieGt5MMJcTgC70Bw4jetTQ9c8rQxdy2166uefAv3X9eToYjEMIAAAAuelRYdGNyZWF0ZS1kYXRlAAB42jMyMLDQNbDUNTINMTK0MjG2MjHXNrCwMjAAAEJlBR8THDGsAAAALnpUWHRtb2RpZnktZGF0ZQAAeNozMjAw0zU00DW0DDGwsDIysTK10DawsDIwAABCEwUe4e0o4wAAAABJRU5ErkJggg%3D%3D" id="TranslationPanel-debugicon">Babelfish debug</span>';
		return ob_get_clean();
	}


	/**
	 * Returns the code for the panel itself
	 *
	 * @return string
	 */
	public function getPanel()
	{
		$translator = $this->translator;
		$metadata = $translator->getMetadata();

		$s = '<h1>Translator debug</h1><div class="nette-inner"><table>';

		$s .= '<tr><th>Actual language</th><td>' . $translator->getLang() . '</td></tr>';

		$s .= '<tr><th rowspan="' . count($translator->getFiles()) . '">Files</th>';
		foreach ($translator->getFiles() as $identifier => $file)
		{
			$s .= '<td><span style="font-weight:bold;">' . $identifier . ':</span> ' .
				$file . '/' . $translator->getLang() . '/' . $identifier . '.mo (.po)</td>'
			;
		}
		$s .= '</tr>';

		// Metadata
		if (!empty($metadata) && is_array($metadata))
		{
			$s .= '<tr><th colspan="2">File headers</th></tr>';
			foreach ($metadata as $file => $data)
			{
				$s .= '<tr><th colspan="2"><a rel="next" href="#">' . $file . ' <abbr>►</abbr></a><table style="display:none">';
				foreach ($data as $key => $value)
				{
					$s .= '<tr><td>' . $key . '</td><td>' . $value . '</td></tr>';
				}
				$s .= '</table></th></tr>';
			}
		}

		// Missing translations
		if (count($this->missingStrings) > 0)
		{
			$s .= '<tr><th colspan="2"><a rel="next" href="#">Missing translations <abbr>►</abbr></a><table style="display:none">';
			foreach ($this->missingStrings as $string => $data)
			{
				$s .= '<tr><td>' . $string . '</td><td>' . $data['message_plural'] . ' (' . $data['form'] . ')</td></tr>';
			}
			$s .= '</table></th></tr>';
		}

		$s .= '</table></div>';

		return $s;
	}


	/**
	 * Register this panel
	 *
	 * @param \SystemContainer $container Nette container
	 * @param \Marten\Babelfish\IEditable $translator
	 * @param int $layout
	 * @param int $height
	 *
	 * @return void
	 */
	public static function register(\SystemContainer $container, IEditable $translator, $layout = NULL, $height = NULL)
	{
		Tracy\Debugger::getBar()->addPanel(new static($container, $translator, $layout, $height));
	}


	/**
	 * Event when the translator found untranslated string
	 *
	 * @param string $lang Language
	 * @param string $message Message
	 * @param string|NULL $message_plural Message plural form
	 * @param int $form Number of items
	 *
	 * @return void
	 */
	public function foundNewString($lang, $message, $messagePlural = NULL, $form = 1)
	{
		$this->missingStrings[$message] = array(
			'lang' => $lang,
			'message_plural' => $messagePlural,
			'form' => $form,
		);
	}


}
