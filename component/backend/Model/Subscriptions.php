<?php
/**
 * @package   AkeebaSubs
 * @copyright Copyright (c)2010-2015 Nicholas K. Dionysopoulos
 * @license   GNU General Public License version 3, or later
 */

namespace Akeeba\Subscriptions\Admin\Model;

defined('_JEXEC') or die;

use FOF30\Container\Container;
use FOF30\Model\DataModel;
use JDate;
use JLoader;

/**
 * The model for the subscription records
 *
 * Tio: to get the subscriptions of a particular user use the User model and its "subscriptions" relation
 *
 * Fields:
 *
 * @property  int			$akeebasubs_subscription_id	Primary key
 * @property  int			$user_id					User ID. FK to user relation.
 * @property  int			$akeebasubs_level_id		Subscription level. FK to level relation.
 * @property  string		$publish_up					Valid from date/time
 * @property  string		$publish_down				Valid to date/time
 * @property  string		$notes						Notes, displayed in admin only
 * @property  int			$enabled					Is this subscription active?
 * @property  string		$processor					Payments processor
 * @property  string		$processor_key				Unique key for the payments processor
 * @property  string		$state						Payment state (N new, P pending, C completed, X cancelled)
 * @property  float			$net_amount					Payable amount without tax
 * @property  float			$tax_amount					Tax portion of payable amount
 * @property  float			$gross_amount				Total payable amount
 * @property  float			$recurring_amount			Total payable amount for further recurring subscriptions
 * @property  float			$tax_percent				% of tax (tax_amount / net_amount)
 * @property  string		$created_on					Date/time when this subscription was created
 * @property  \stdClass		$params						Parameters, used by custom fields and plugins
 * @property  string		$ip							IP address of the user who created this subscription
 * @property  string		$ip_country					Country of the user who created this subscription, based on IP geolocation
 * @property  int			$akeebasubs_coupon_id		Coupon code used. FK to coupon relation.
 * @property  int			$akeebasubs_upgrade_id		Upgrade rule used. FK to upgrade relation
 * @property  int			$akeebasubs_affiliate_id	NO LONGER USED IN CORE. Store an affiliate ID (plugin specific)
 * @property  float			$affiliate_comission		NO LONGER USED IN CORE. Store the commission amount of you affiliate (plugin specific)
 * @property  int			$akeebasubs_invoice_id		Invoice issues. FK to invoice relation.
 * @property  float			$prediscount_amount			Total amount, before taxes and before any discount was applied.
 * @property  float			$discount_amount			Discount amount (before taxes), applied over the prediscount_amount
 * @property  int			$contact_flag				Which expiration emails we've sent. 0 none, 1 first, 2 second, 3 after expiration
 * @property  string		$first_contact				Date/time of first expiration notification email sent.
 * @property  string		$second_contact				Date/time of second expiration notification email sent.
 * @property  string		$after_contact				Date/time of post-expiration notification email sent.
 *
 * Filters / state:
 *
 * @method  $this _noemail() _noemail(bool $v)          Do not send email on save when true (resets after successful save)
 * @method  $this refresh()  refresh(int $v)            Set to 1 to ignore filters, used for running integrations on all subscriptions
 * @method  $this filter_discountmode() filter_discountmode(string $v)  Discount filter mode (none, coupon, upgrade)
 * @method  $this filter_discountcode() filter_discountcode(string $v)  Discount code search (coupon code/title or upgrade title)
 * @method  $this publish_up() publish_up(string $v)      Subscriptions coming up after date
 * @method  $this publish_down() publish_down(string $v)  Subscriptions coming up before date (if publish_up is set), or subscriptions expiring before date (if publish_up is not set)
 * @method  $this since() since(string $v)              Subscriptions created after this date
 * @method  $this until() until(string $v)              Subscriptions created before this date
 * @method  $this expires_from() expires_from(string $v)  Subscriptions expiring from this date onwards
 * @method  $this expires_to() expires_to(string $v)      Subscriptions expiring before this date
 * @method  $this nozero() nozero(bool $v)              Set to 1 to skip free (net_amount=0) subscriptions
 * @method  $this search() search(string $v)            Search by user info (username, name, email, business name or VAT number)
 * @method  $this subid() subid(mixed $ids)             Search by subscription ID (int or array of int)
 * @method  $this level() level(mixed $ids)             Search by subscription level ID (int or array of int)
 * @method  $this coupon_id() coupon_id(mixed $ids)     Search by coupon ID (int or array of int)
 * @method  $this paystate() paystate(mixed $states)    Search by payment state (string or array of string)
 * @method  $this processor() processor(string $v)      Search by payment processor identifier
 * @method  $this ip() ip(string $v)                    Search by IP of user signing up
 * @method  $this ip_country() ip_country(string $v)    Search by auto-detected country code based on IP
 * @method  $this paykey() paykey(string $key)          Search by payment key
 * @method  $this user_id() user_id(mixed $ids)         Search by user ID (int or array of int)
 * @method  $this enabled() enabled(int $enabled)       Search by enabled statis
 *
 * Relations:
 *
 * @property-read  Users	 $user		The subscription user
 * @property-read  Levels	 $level 	The subscription level. Note: the method is a filter, the property is a relation!
 * @property-read  Coupons	 $coupon	The coupon used (if akeebasubs_coupon_id is not empty)
 * @property-read  Upgrades	 $upgrade	The upgrade rule used (if akeebasubs_upgrade_id is not empty)
 * @property-read  Invoices  $invoice	The invoice issued (if akeebasubs_invoice_id is not empty)
 */
class Subscriptions extends DataModel
{
	use Mixin\JsonData;

	public function __construct(Container $container, array $config = array())
	{
		parent::__construct($container, $config);

		// Add the filtering behaviour
		$this->addBehaviour('Filters');
		$this->blacklistFilters([
			'publish_up',
			'publish_down',
		    'created_on',
		]);

		// Set up relations
		$this->hasOne('user', 'Users', 'user_id', 'user_id');
		$this->hasOne('level', 'Levels');
		$this->hasOne('coupon', 'Coupons');
		$this->hasOne('upgrade', 'Upgrades');
		$this->hasOne('invoice', 'Invoices', 'akeebasubs_subscription_id', 'akeebasubs_subscription_id');
	}

	/**
	 * Map state variables from their old names to their new names, for a modicum of backwards compatibility
	 *
	 * @param   \JDatabaseQuery  $query
	 */
	protected function onBeforeBuildQuery(\JDatabaseQuery &$query)
	{
		// Map state variables to what is used by automatic filters
		foreach (
			[
				'subid'     => 'akeebasubs_subscription_id',
				'level'     => 'akeebasubs_level_id',
				'paystate'  => 'state',
				'paykey'    => 'processor_key',
			    'coupon_id' => 'akeebasubs_coupon_id',
			] as $from => $to)
		{
			$this->setState($to, $this->getState($from, null));
		}

		// Apply filtering by user. This is a relation filter, it needs to go before the main query builder fires.
		$this->filterByUser();
	}

	/**
	 * Apply additional filtering to the select query
	 *
	 * @param   \JDatabaseQuery  $query  The query to modify
	 */
	protected function onAfterBuildQuery(\JDatabaseQuery $query)
	{
		// If the refresh flag is set in the state we must return all records, without honoring any kind of filter,
		// custom WHERE clause or relation filter.
		$refresh = $this->getState('refresh', null, 'int');

		if ($refresh)
		{
			// Remove already added WHERE clauses
			$query->clear('where');

			// Remove user-defined WHERE clauses
			$this->whereClauses = [];

			// Remove relation filters which would result in WHERE clauses with sub-queries
			$this->relationFilters = [];

			// Do not process anything else, we're done
			return;
		}

		// Filter by discount mode and code (filter_discountmode / filter_discountcode)
		$this->filterByDiscountCode($query);

		// Filter by publish_up / publish_down dates
		$this->filterByDate($query);

		// Filter by created date (since / until)
		$this->filterByCreatedOn($query);

		// Filter by expiration date range (expires_from / expires_to)
		$this->filterByExpirationDate($query);

		// Fitler by non-free subscriptions (nozero)
		$this->filterByNonFree($query);
	}

	/**
	 * Apply select query filtering by username, email, business name or VAT / tax ID number
	 *
	 * @return  void
	 */
	protected function filterByUser()
	{
		// User search feature
		$search = $this->getState('search', null, 'string');

		if ($search)
		{
			// First get the Joomla! users fulfilling the criteria
			/** @var JoomlaUsers $users */
			$users = $this->container->factory->model('JoomlaUsers')->setIgnoreRequest(true);
			$userIDs = $users->search($search)->with([])->get(true)->modelKeys();

			// Then do a relation filter against the user relation
			$this->whereHas('user', function (\JDatabaseQuery $q) use($userIDs, $search) {
				$q->where(
					'(' .
					'(' . $q->qn('user') . ' IN (' . implode(',', array_map(array($q, 'q'), $userIDs)) . '))' .
					' OR ' .
					'(' . $q->qn('businessname') . ' LIKE ' . $q->qn("%$search%") . ')' .
					' OR ' .
					'(' . $q->qn('vatnumber') . ' LIKE ' . $q->qn("%$search%") . ')' .
					')'
				);
			});
		}
	}

	/**
	 * Apply select query filtering by discount code
	 *
	 * @param   \JDatabaseQuery  $query  The query to modify
	 *
	 * @return  void
	 */
	protected function filterByDiscountCode(\JDatabaseQuery $query)
	{
		$db = $this->getDbo();

		$tableAlias = $this->getBehaviorParam('tableAlias', null);
		$tableAlias = !empty($tableAlias) ? ($db->qn($tableAlias) . '.') : '';

		$filter_discountmode = $this->getState('filter_discountmode', null, 'cmd');
		$filter_discountcode = $this->getState('filter_discountcode', null, 'string');

		$coupon_ids  = array();
		$upgrade_ids = array();

		switch ($filter_discountmode)
		{
			case 'none':
				$query->where(
					'(' .
					'(' . $tableAlias . $db->qn('akeebasubs_coupon_id') . ' = ' . $db->q(0) . ')'
					. ' AND ' .
					'(' . $tableAlias . $db->qn('akeebasubs_upgrade_id') . ' = ' . $db->q(0) . ')'
					. ')'
				);
				break;

			case 'coupon':
				$query->where(
					'(' .
					'(' . $tableAlias . $db->qn('akeebasubs_coupon_id') . ' > ' . $db->q(0) . ')'
					. ' AND ' .
					'(' . $tableAlias . $db->qn('akeebasubs_upgrade_id') . ' = ' . $db->q(0) . ')'
					. ')'
				);

				if ($filter_discountcode)
				{
					/** @var Coupons $couponsModel */
					$couponsModel = $this->container->factory->model('Coupons');

					$coupons = $couponsModel
						->search($filter_discountcode)
						->get(true);

					if (!empty($coupons))
					{
						foreach ($coupons as $coupon)
						{
							$coupon_ids[] = $coupon->akeebasubs_coupon_id;
						}
					}
					unset($coupons);
				}
				break;

			case 'upgrade':
				$query->where(
					'(' .
					'(' . $tableAlias . $db->qn('akeebasubs_coupon_id') . ' = ' . $db->q(0) . ')'
					. ' AND ' .
					'(' . $tableAlias . $db->qn('akeebasubs_upgrade_id') . ' > ' . $db->q(0) . ')'
					. ')'
				);
				if ($filter_discountcode)
				{
					/** @var Upgrades $upgradesModel */
					$upgradesModel = $this->container->factory->model('Upgrades');

					$upgrades = $upgradesModel
						->search($filter_discountcode)
						->get(true);

					if (!empty($upgrades))
					{
						foreach ($upgrades as $upgrade)
						{
							$upgrade_ids[] = $upgrade->akeebasubs_upgrade_id;
						}
					}
					unset($upgrades);
				}
				break;

			default:
				if ($filter_discountcode)
				{
					/** @var Coupons $couponsModel */
					$couponsModel = $this->container->factory->model('Coupons');

					$coupons = $couponsModel
						->search($filter_discountcode)
						->get(true);

					if (!empty($coupons))
					{
						foreach ($coupons as $coupon)
						{
							$coupon_ids[] = $coupon->akeebasubs_coupon_id;
						}
					}
					unset($coupons);
				}

				if ($filter_discountcode)
				{
					/** @var Upgrades $upgradesModel */
					$upgradesModel = $this->container->factory->model('Upgrades');

					$upgrades = $upgradesModel
						->search($filter_discountcode)
						->get(true);

					if (!empty($upgrades))
					{
						foreach ($upgrades as $upgrade)
						{
							$upgrade_ids[] = $upgrade->akeebasubs_upgrade_id;
						}
					}

					unset($upgrades);
				}
				break;
		}

		if (!empty($coupon_ids) && !empty($upgrade_ids))
		{
			$query->where(
				'(' .
				'(' . $tableAlias . $db->qn('akeebasubs_coupon_id') . ' IN (' . $db->q(implode(',', $coupon_ids)) . '))'
				. ' OR ' .
				'(' . $tableAlias . $db->qn('akeebasubs_upgrade_id') . ' IN (' . $db->q(implode(',', $upgrade_ids)) . '))'
				. ')'
			);
		}
		elseif (!empty($coupon_ids))
		{
			$query->where($tableAlias . $db->qn('akeebasubs_coupon_id') . ' IN (' . $db->q(implode(',', $coupon_ids)) . ')');
		}
		elseif (!empty($upgrade_ids))
		{
			$query->where($tableAlias . $db->qn('akeebasubs_upgrade_id') . ' IN (' . $db->q(implode(',', $upgrade_ids)) . ')');
		}
	}

	/**
	 * Filter the select query by publish_up / publish_down date
	 *
	 * @param   \JDatabaseQuery  $query  The query to modify
	 *
	 * @return  void
	 */
	protected function filterByDate(\JDatabaseQuery $query)
	{
		$db = $this->getDbo();

		$tableAlias = $this->getBehaviorParam('tableAlias', null);
		$tableAlias = !empty($tableAlias) ? ($db->qn($tableAlias) . '.') : '';

		\JLoader::import('joomla.utilities.date');
		$publish_up = $this->getState('publish_up', null, 'string');
		$publish_down = $this->getState('publish_down', null, 'string');

		$regex = '/^\d{1,4}(\/|-)\d{1,2}(\/|-)\d{2,4}[[:space:]]{0,}(\d{1,2}:\d{1,2}(:\d{1,2}){0,1}){0,1}$/';

		// Filter the dates
		$from = trim($publish_up);

		if (empty($from))
		{
			$from = '';
		}
		else
		{
			if (!preg_match($regex, $from))
			{
				$from = '2001-01-01';
			}

			$jFrom = new JDate($from);
			$from  = $jFrom->toUnix();

			if ($from == 0)
			{
				$from = '';
			}
			else
			{
				$from = $jFrom->toSql();
			}
		}

		$to = trim($publish_down);

		if (empty($to) || ($to == '0000-00-00') || ($to == '0000-00-00 00:00:00'))
		{
			$to = '';
		}
		else
		{
			if (!preg_match($regex, $to))
			{
				$to = '2037-01-01';
			}

			$jTo = new JDate($to);
			$to  = $jTo->toUnix();

			if ($to == 0)
			{
				$to = '';
			}
			else
			{
				$to = $jTo->toSql();
			}
		}

		if (!empty($from) && !empty($to))
		{
			// Filter from-to dates
			$query->where(
				$tableAlias . $db->qn('publish_up') . ' >= ' .  $db->q($from)
			);
			$query->where(
				$tableAlias . $db->qn('publish_up') . ' <= ' . $db->q($to)
			);
		}
		elseif (!empty($from) && empty($to))
		{
			// Filter after date
			$query->where(
				$tableAlias . $db->qn('publish_up') . ' >= ' . $db->q($from)
			);
		}
		elseif (empty($from) && !empty($to))
		{
			// Filter up to a date
			$query->where(
				$tableAlias . $db->qn('publish_down') . ' <= ' . $db->q($to)
			);
		}
	}

	/**
	 * Filter the select query by created date (since / until)
	 *
	 * @param   \JDatabaseQuery  $query  The query to modify
	 *
	 * @return  void
	 */
	protected function filterByCreatedOn(\JDatabaseQuery $query)
	{
		$db = $this->getDbo();

		$tableAlias = $this->getBehaviorParam('tableAlias', null);
		$tableAlias = !empty($tableAlias) ? ($db->qn($tableAlias) . '.') : '';

		\JLoader::import('joomla.utilities.date');
		$since = $this->getState('since', null, 'string');
		$until = $this->getState('until', null, 'string');

		$regex = '/^\d{1,4}(\/|-)\d{1,2}(\/|-)\d{1,2}[[:space:]]{0,}(\d{1,2}:\d{1,2}(:\d{1,2}){0,1}){0,1}$/';

		// "Since" queries
		$since = trim($since);

		if (empty($since) || ($since == '0000-00-00') || ($since == '0000-00-00 00:00:00') || ($since == $db->getNullDate()))
		{
			$since = '';
		}
		else
		{
			if (!preg_match($regex, $since))
			{
				$since = '2001-01-01';
			}

			$jFrom = new JDate($since);
			$since = $jFrom->toUnix();

			if ($since == 0)
			{
				$since = '';
			}
			else
			{
				$since = $jFrom->toSql();
			}

			// Filter from-to dates
			$query->where(
				$tableAlias . $db->qn('created_on') . ' >= ' . $db->q($since)
			);
		}

		// "Until" queries
		$until = trim($until);

		if (empty($until) || ($until == '0000-00-00') || ($until == '0000-00-00 00:00:00') || ($until == $db->getNullDate()))
		{
			$until = '';
		}
		else
		{
			if (!preg_match($regex, $until))
			{
				$until = '2037-01-01';
			}

			$jFrom = new JDate($until);
			$until = $jFrom->toUnix();

			if ($until == 0)
			{
				$until = '';
			}
			else
			{
				$until = $jFrom->toSql();
			}

			$query->where(
				$tableAlias . $db->qn('created_on') . ' <= ' . $db->q($until)
			);
		}
	}

	/**
	 * Filter the select query by expiration date range (expires_from / expires_to)
	 *
	 * @param   \JDatabaseQuery  $query  The query to modify
	 *
	 * @return  void
	 */
	protected function filterByExpirationDate(\JDatabaseQuery $query)
	{
		$db = $this->getDbo();

		$tableAlias = $this->getBehaviorParam('tableAlias', null);
		$tableAlias = !empty($tableAlias) ? ($db->qn($tableAlias) . '.') : '';

		\JLoader::import('joomla.utilities.date');
		$expires_from = $this->getState('expires_from', null, 'string');
		$expires_to = $this->getState('expires_to', null, 'string');

		$regex = '/^\d{1,4}(\/|-)\d{1,2}(\/|-)\d{1,2}[[:space:]]{0,}(\d{1,2}:\d{1,2}(:\d{1,2}){0,1}){0,1}$/';

		$from = trim($expires_from);

		if (empty($from))
		{
			$from = '';
		}
		else
		{
			if (!preg_match($regex, $from))
			{
				$from = '2001-01-01';
			}

			$jFrom = new JDate($from);
			$from  = $jFrom->toUnix();

			if ($from == 0)
			{
				$from = '';
			}
			else
			{
				$from = $jFrom->toSql();
			}
		}

		$to = trim($expires_to);

		if (empty($to) || ($to == '0000-00-00') || ($to == '0000-00-00 00:00:00'))
		{
			$to = '';
		}
		else
		{
			if (!preg_match($regex, $to))
			{
				$to = '2037-01-01';
			}

			$jTo = new JDate($to);
			$to  = $jTo->toUnix();

			if ($to == 0)
			{
				$to = '';
			}
			else
			{
				$to = $jTo->toSql();
			}
		}

		if (!empty($from) && !empty($to))
		{
			// Filter from-to dates
			$query->where(
				$tableAlias . $db->qn('publish_down') . ' >= ' . $db->q($from)
			);
			$query->where(
				$tableAlias . $db->qn('publish_down') . ' <= ' . $db->q($to)
			);
		}
		elseif (!empty($from) && empty($to))
		{
			// Filter after date
			$query->where(
				$tableAlias . $db->qn('publish_down') . ' >= ' . $db->q($from)
			);
		}
		elseif (empty($from) && !empty($to))
		{
			// Filter up to a date
			$query->where(
				$tableAlias . $db->qn('publish_down') . ' <= ' . $db->q($to)
			);
		}
	}

	/**
	 * Filter the select query by non-free subscriptions (nozero)
	 *
	 * @param   \JDatabaseQuery  $query  The query to modify
	 *
	 * @return  void
	 */
	protected function filterByNonFree(\JDatabaseQuery $query)
	{
		$db = $this->getDbo();

		$tableAlias = $this->getBehaviorParam('tableAlias', null);
		$tableAlias = !empty($tableAlias) ? ($db->qn($tableAlias) . '.') : '';

		$nozero = $this->getState('nozero', null, 'int');

		if (!empty($nozero))
		{
			$query->where(
				$tableAlias . $db->qn('net_amount') . ' > ' . $db->q('0')
			);
		}
	}

	/**
	 * Automatically process a list of subscriptions loaded off the database
	 *
	 * @param   Subscriptions[]  $resultArray  The list of loaded subscriptions to process
	 */
	protected function onAfterGetItemsArray(array &$resultArray)
	{
		// Implement the subscription automatic expiration
		if (empty($resultArray))
		{
			return;
		}

		if ($this->getState('skipOnProcessList', 0))
		{
			return;
		}

		JLoader::import('joomla.utilities.date');
		$jNow = new JDate();
		$uNow = $jNow->toUnix();

		foreach ($resultArray as $index => &$row)
		{
			if (!property_exists($row, 'params'))
			{
				continue;
			}

			// TODO: This should no longer be necessary
			if (!is_array($row->params))
			{
				if (!empty($row->params))
				{
					$row->params = json_decode($row->params, true);
				}
			}
			if (is_null($row->params) || empty($row->params))
			{
				$row->params = array();
			}

			$triggered = false;

			if (($row->getFieldValue('state', 'N') != 'C') && $row->enabled)
			{
				$row->enabled = false;
				$row->save();

				continue;
			}

			if ($row->publish_down && ($row->publish_down != '0000-00-00 00:00:00'))
			{
				$regex = '/^\d{1,4}(\/|-)\d{1,2}(\/|-)\d{2,4}[[:space:]]{0,}(\d{1,2}:\d{1,2}(:\d{1,2}){0,1}){0,1}$/';

				if (!preg_match($regex, $row->publish_down))
				{
					$row->publish_down = '2037-01-01';
				}

				if (!preg_match($regex, $row->publish_up))
				{
					$row->publish_up = '2001-01-01';
				}

				$jDown = new JDate($row->publish_down);
				$jUp   = new JDate($row->publish_up);

				if (($uNow >= $jDown->toUnix()) && $row->enabled)
				{
					$row->enabled = 0;
					$triggered    = true;
				}
				elseif (($uNow >= $jUp->toUnix()) && !$row->enabled && ($uNow < $jDown->toUnix()))
				{
					$row->enabled = 1;
					$triggered    = true;
				}
			}

			if ($triggered)
			{
				$row->save();
			}
		}
	}

	/**
	 * Handle the _noemail flag, used to avoid sending emails after we modify a subscriptions
	 *
	 * @param   array  $data
	 */
	protected function onBeforeBind(&$data)
	{
		if (!is_array($data))
		{
			return;
		}

		if (isset($data['_noemail']))
		{
			$this->setState('_noemail', $data['_noemail']);
			unset($data['_noemail']);
		}
	}

	/**
	 * Reset the _noemail flag after save
	 */
	protected function onAfterSave()
	{
		$this->setState('_noemail', false);
	}

	/**
	 * Decode the JSON-encoded params field into an associative array when loading the record
	 *
	 * @param   string  $value  JSON data
	 *
	 * @return  array  The decoded array
	 */
	protected function getParamsAttribute($value)
	{
		return $this->getAttributeForJson($value);
	}

	/**
	 * Encode the params array field into a JSON-encoded string when saving the record
	 *
	 * @param   array  $value  The array
	 *
	 * @return  string  The JSON-encoded data
	 */
	protected function setParamsAttribute($value)
	{
		return $this->setAttributeForJson($value);
	}
}