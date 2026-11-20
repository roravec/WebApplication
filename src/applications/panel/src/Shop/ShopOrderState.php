<?php
namespace Panel\Shop;

/**
 * Changes the state of an order IN THE SHOP the same way the PrestaShop admin does: a new `order_histories` record
 * (PrestaShop then runs its hooks, stock / payment logic and — with sendemail=1 — sends the e-mail of that state).
 */
class ShopOrderState
{
    /** @var PrestaShopApi */ private $api;

    public function __construct(PrestaShopApi $api)
    {
        $this->api = $api;
    }

    /**
     * @param string $expectedReference the order reference the panel shows — the shop's order must match (safety)
     * @return int the order's state in the shop after the change
     */
    public function change(int $orderId, int $shopId, string $expectedReference, int $stateId, bool $sendEmail): int
    {
        $ctx = ['id_shop' => $shopId];
        $o = $this->api->get('orders/' . $orderId, $ctx)->order;
        if ((string)$o->reference !== $expectedReference) {
            throw new \RuntimeException('Objednávka č. ' . $orderId . ' v e-shope má iné číslo (' . $o->reference . ' namiesto ' . $expectedReference
                . ') — pripojenie asi nepatrí k tejto databáze. Nič sa nezmenilo.');
        }
        if ((int)$o->current_state === $stateId) {
            return $stateId;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><prestashop><order_history><id_order>' . $orderId . '</id_order>'
             . '<id_order_state>' . $stateId . '</id_order_state></order_history></prestashop>';
        $this->api->post('order_histories', $xml, $ctx + ['sendemail' => $sendEmail ? 1 : 0]);
        // a single resource comes back as <order> (verified on PrestaShop 1.7.6.7) — read it the same way as above
        return (int)$this->api->get('orders/' . $orderId, $ctx)->order->current_state;
    }
}
