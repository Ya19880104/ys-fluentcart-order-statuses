<?php
/**
 * One realistic settings document, shared by the test files.
 *
 * @package YangSheep\FluentCart\OrderStatuses
 */

declare( strict_types=1 );

/**
 * The AiSALE-shaped configuration: a paid-only sourcing step on the order axis,
 * a warehouse step on the shipping axis, and relabelled built-ins.
 */
final class YsStatusFixture {

	/**
	 * @return array
	 */
	public static function full(): array {
		return array(
			'restore_on_payment' => 'yes',
			'order'              => array(
				array(
					'slug'                => 'sourcing',
					'label'               => '美國採購中',
					'color'               => '#DB8A3E',
					'description'         => 'Paid; buying the item in the US.',
					'editable'            => true,
					'enabled'             => true,
					'payment_requirement' => 'paid_only',
					'on_payment'          => 'keep',
					'sort_order'          => 10,
				),
				array(
					'slug'                => 'core_decides',
					'label'               => 'Core decides',
					'color'               => '#2563eb',
					'editable'            => true,
					'enabled'             => true,
					'payment_requirement' => 'any',
					'on_payment'          => 'let_core_decide',
					'sort_order'          => 20,
				),
				array(
					'slug'                => 'hidden_step',
					'label'               => 'Hidden step',
					'color'               => '#7c3aed',
					'editable'            => false,
					'enabled'             => true,
					'payment_requirement' => 'any',
					'on_payment'          => 'keep',
					'sort_order'          => 30,
				),
				array(
					'slug'                => 'off_step',
					'label'               => 'Switched off',
					'color'               => '#dc2626',
					'editable'            => true,
					'enabled'             => false,
					'payment_requirement' => 'any',
					'on_payment'          => 'keep',
					'sort_order'          => 40,
				),
			),
			'shipping'           => array(
				array(
					'slug'       => 'us_warehouse',
					'label'      => '已到美國倉',
					'color'      => '#16244A',
					'editable'   => true,
					'enabled'    => true,
					'sort_order' => 10,
				),
			),
			'overrides'          => array(
				'order'    => array( 'processing' => array( 'label' => '處理中', 'color' => '#2563EB' ) ),
				'payment'  => array( 'pending' => array( 'label' => '待付款' ) ),
				'shipping' => array( 'unshipped' => array( 'label' => '未出貨' ) ),
			),
		);
	}

	/**
	 * FluentCart's own order-status map, unfiltered.
	 *
	 * @return array<string,string>
	 */
	public static function coreOrderStatuses(): array {
		return array(
			'processing' => 'Processing',
			'completed'  => 'Completed',
			'on-hold'    => 'On Hold',
			'canceled'   => 'Canceled',
			'failed'     => 'Failed',
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function coreEditableOrderStatuses(): array {
		return array(
			'on-hold'    => 'On Hold',
			'processing' => 'Processing',
			'completed'  => 'Completed',
			'canceled'   => 'Canceled',
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function coreShippingStatuses(): array {
		return array(
			'unshipped'   => 'Unshipped',
			'shipped'     => 'Shipped',
			'delivered'   => 'Delivered',
			'unshippable' => 'Unshippable',
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function corePaymentStatuses(): array {
		return array(
			'pending'            => 'Pending',
			'paid'               => 'Paid',
			'partially_paid'     => 'Partially Paid',
			'failed'             => 'Failed',
			'refunded'           => 'Refunded',
			'partially_refunded' => 'Partially Refunded',
			'authorized'         => 'Authorized',
			'payment_scheduled'  => 'Payment Scheduled',
		);
	}
}
