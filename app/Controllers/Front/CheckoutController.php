<?php 

namespace Adtrak\Skips\Controllers\Front;

use Adtrak\Skips\Facades\Front;
use Adtrak\Skips\Models\Skip;
use Adtrak\Skips\Models\Permit;
use Adtrak\Skips\Controllers\Front\LocationController;
use Adtrak\Skips\Controllers\Front\SkipController;

class CheckoutController extends Front
{
	protected $location;
	protected $skip;
	protected $checkPostcode;

    /**
     * CheckoutController constructor.
     */
    public function __construct()
	{
		$this->location = new LocationController;
		$this->skip = new SkipController;
		$this->addActions();
	}

    /**
     *
     */
    public function addActions()
	{
		add_action('ash_checkout', [$this, 'handler']);
	}

	public function handler()
	{
		if (isset($_POST['skip_id'])) {
			$_SESSION['ash_skip'] = $_POST['skip_id'];
		}

		if (isset($_POST['autocomplete'])) {
			$this->checkPostcode = $this->location->checkPostcode();
		}

		if (!isset($_SESSION['ash_location']) || $_SESSION['ash_location'] == null) {
			$this->location->form();
		} else if ($this->checkPostcode && !isset($_SESSION['ash_skip']) || $_SESSION['ash_skip'] == null) {
			$this->skip->loop();
		}
		
		if (isset($_SESSION['ash_skip']) && isset($_SESSION['ash_location'])) {
			$this->checkout();			
		}
	}

    /**
     *
     */
    public function checkout()
	{
		$this->beforeCheckout();

		$skip = Skip::findOrFail($_SESSION['ash_skip']);
		$permit = Permit::all();

		$this->checkoutForm($skip, $permit);

		$this->afterCheckout();
	}

    /**
     * @param $skip
     */
    public function beforeCheckout()
	{
		$template = $this->templateLocator('checkout/header.php');
		include_once $template;
	}

    /**
     *
     */
    public function afterCheckout()
	{
		$template = $this->templateLocator('checkout/footer.php');
		include_once $template;
	}

    /**
     * @param $skip
     * @param $permits
     */
    public function checkoutForm($skip, $permits)
	{
		$template = $this->templateLocator('checkout/form.php');
		include_once $template;
	}
}