<?php 

namespace Adtrak\Skips\Controllers\Front;

use Adtrak\Skips\Facades\Front;
use Adtrak\Skips\Models\Skip;
use Adtrak\Skips\Models\Permit;
use Adtrak\Skips\Models\Coupon;
use Adtrak\Skips\Models\Order;
use Adtrak\Skips\Models\OrderItem;
use Adtrak\Skips\Controllers\Payments\PayPalController as PayPal;

/**
 * Class CartController
 * @package Adtrak\Skips\Controllers\Front
 */
class CartController extends Front
{
    /**
     * @var
     */
	public $skip;

	/**
     * @var
     */
	public $coupon;

    /**
     * @var
     */
	public $permit;

    /**
     * @var PayPal
     */
	public $paypal;

    /**
     * @var
     */
	public $orderDetails;

    /**
     * CartController constructor.
     */
	public function __construct()
	{
        $this->paypal = new PayPal();
        $this->addActions();
	}

    /**
     * set up the actions so they can be hooked
     */
	public function addActions()
	{
		add_action('ash_cart', [$this, 'cart']);
	}

    /**
     * set up the cart hooks / before and after
     */
	public function cart()
	{
		$this->beforeCart();

		if (isset($_POST['ash_submit'])) {
			$this->cartDetails();
		} else {
			echo 'Sorry, looks like you have not placed an order';
		}

		$this->afterCart();		
	}

    /**
     * include the cart header
     */
	public function beforeCart()
	{
		$template = $this->templateLocator('cart/header.php');
		include_once $template;
	}

    /**
     * Process the cart details, generate a payment link, bind data for save
     */
	public function cartDetails()
	{
        $skip = $this->getSkip();
		$permit = $this->getPermit();		
		$coupon = $this->getCoupon();
		$details = $this->getOrderDetails();
		$subTotal = $skip->price;

		if ($coupon && $coupon->type == 'flat') {
			$couponValue = $coupon->amount * -1;
			$subTotal = $skip->price + $couponValue;
		} 
		
		if ($coupon && $coupon->type == 'percentage') {
			$couponValue = $skip->price * ($coupon->amount / 100) * -1;
			$subTotal = $skip->price + $couponValue; 
		}
		
		if ($permit) {
			$total = $subTotal + $permit->price;
		} else {
			$total = $subTotal;
		}

        $paypal = $this->getPaymentLink($skip, $total, $permit, $coupon);
	    $template = $this->templateLocator('cart/details.php');
		include_once $template;
	}

    /**
     * After cart function
     */
	public function afterCart()
	{
	    // to be
	}

    /**
     * Generate a payment link based on details
     *
     * @param $skip
     * @param $total
     * @param $permit
     * @param $coupon
     * @return null|string
     */
    public function getPaymentLink($skip, $total, $permit, $coupon)
    {
        return $this->paypal->generateLink($skip, $total, $permit, $coupon);
    }

    /**
     * Get the skip based on the ID passed.
     *
     * @return mixed
     */
    public function getSkip()
	{
		if ($_POST['ash_skip']) {
			$this->skip = Skip::findOrFail($_POST['ash_skip']);
		}

		if ($this->skip) {
			$_SESSION['ash_details']['skip'] = $this->skip;
		} else {
			$_SESSION['ash_details']['skip'] = [];
		}

		return $this->skip;
	}

    /**
     * Get hte permit based on the ID passed.
     *
     * @return mixed
     */
	public function getPermit()
	{
		if ($_POST['ash_permit']) {
			$this->permit = Permit::findOrFail($_POST['ash_permit']);
		}

		if ($this->permit) {
			$_SESSION['ash_details']['permit'] = $this->permit;
		} else {
			$_SESSION['ash_details']['permit'] = [];
		}

		return $this->permit;		
	}

    /**
     * Get the first coupon, if it exists by name, and check the dates
     *
     * @return mixed
     */
	public function getCoupon()
	{
		$_SESSION['ash_details']['coupon'] = [];
		
		if ($_POST['ash_coupon']) {
			$coupon = Coupon::where('code', '=', $_POST['ash_coupon'])->first();
			
			$datePasses = true;
			$today = date('Y-m-d');

			// if today is less than it's start, when it's not null
			if(!(!is_null($coupon->starts) && $coupon->starts <= $today)) {
				$datePasses = false;
			}

			// if today is greater than it's than, when it's not null
			if(!(!is_null($coupon->expires) && $coupon->expires >= $today)) {
				$datePasses = false;			
			}

			if ($datePasses) {
				$this->coupon = $coupon;
				$_SESSION['ash_details']['coupon'] = $this->coupon;	
			}
		} 
		
		return $this->coupon;		
	}

    /**
     * Get the details from the Post, bind them as an object
     *
     * @return object
     */
	public function getOrderDetails()
	{
		if ($_POST['ash_submit']) {
			$this->orderDetails = (object) $_POST;
		}

		if ($this->orderDetails) {
			$_SESSION['ash_details']['user'] = $this->orderDetails;
		} else {
			$_SESSION['ash_details']['user'] = [];
		}

		return $this->orderDetails;
	}
}