<?php
/**
 * @package   AkeebaSubs
 * @copyright Copyright (c)2010-2015 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Subscriptions\Admin\Form\Field;

use Akeeba\Subscriptions\Admin\Helper\ComponentParams;
use FOF30\Form\Field\Text;
use SimpleXMLElement;

defined('_JEXEC') or die;

class MoneyEntry extends Text
{
	public function setup(SimpleXMLElement $element, $value, $group = null)
	{
		$x = parent::setup($element, $value, $group);

		static $currencyPosition = null;
		static $currencySymbol = null;

		if (is_null($currencyPosition))
		{
			$currencyPosition = ComponentParams::getParam('currencypos','before');
			$currencySymbol = ComponentParams::getParam('currencysymbol','€');
		}

		if ($currencyPosition == 'before')
		{
			$this->form->setFieldAttribute($this->fieldname, 'prepend_text', $currencySymbol);
		}
		else
		{
			$this->form->setFieldAttribute($this->fieldname, 'append_text', $currencySymbol);
		}

		return $x;
	}
}