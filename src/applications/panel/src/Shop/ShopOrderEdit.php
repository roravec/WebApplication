<?php
namespace Panel\Shop;

/**
 * Changes of an order IN THE SHOP through the standard Webservice (the shops' code is not modified) — verified on
 * PrestaShop 1.7.6.7 (dev1) and 9.2.1 (dev4) copies, 2026-11-20:
 * - tracking number: `order_carriers` PUT tracking_number (PrestaShop copies it to orders.shipping_number);
 * - delivery / invoice address: a NEW address (copy of the old one with the changes, same customer) is POSTed and the
 *   order is pointed to it (`orders` PUT id_address_*) — like the shop admin, the old address stays for history and
 *   other orders that use it; the PUT changes only that field (+ date_upd), order rows and totals stay.
 * - EVERY call carries the shop context (id_shop): PrestaShop 9 does not find an order / address outside it.
 * - The order is checked against the reference the panel shows (safety: the connection must belong to this database).
 * Order lines (products, quantities, prices) are NOT edited: the Webservice does not recalculate totals, taxes or stock.
 */
class ShopOrderEdit
{
    const ADDRESS_FIELDS = ['firstname' => 'Meno', 'lastname' => 'Priezvisko', 'company' => 'Firma', 'address1' => 'Ulica a číslo',
        'address2' => 'Adresa — 2. riadok', 'postcode' => 'PSČ', 'city' => 'Mesto', 'phone' => 'Telefón', 'phone_mobile' => 'Mobil',
        'dni' => 'IČO', 'vat_number' => 'DIČ / IČ DPH', 'other' => 'Poznámka k adrese'];

    /** @var PrestaShopApi */ private $api;
    /** @var array resource => read-only fields */ private $readOnly = [];

    public function __construct(PrestaShopApi $api)
    {
        $this->api = $api;
    }

    /** The order document, checked against the reference the panel shows. */
    private function order(int $orderId, int $shopId, string $reference): \SimpleXMLElement
    {
        $doc = $this->api->get('orders/' . $orderId, ['id_shop' => $shopId]);
        if ((string)$doc->order->reference !== $reference) {
            throw new \RuntimeException('Objednávka č. ' . $orderId . ' v e-shope má iné číslo (' . $doc->order->reference . ' namiesto ' . $reference
                . ') — pripojenie asi nepatrí k tejto databáze. Nič sa nezmenilo.');
        }
        return $doc;
    }

    /** Sets the tracking number of the order's (last) carrier. */
    public function setTracking(int $orderId, int $shopId, string $reference, string $number): void
    {
        $this->order($orderId, $shopId, $reference);
        $ctx = ['id_shop' => $shopId];
        $list = $this->api->get('order_carriers', $ctx + ['display' => '[id]', 'filter' => ['id_order' => '[' . $orderId . ']'], 'sort' => '[id_DESC]']);
        $id = isset($list->order_carriers->order_carrier[0]) ? (int)$list->order_carriers->order_carrier[0]->id : 0;
        if (!$id) {
            throw new \RuntimeException('Objednávka nemá v e-shope dopravcu (order_carrier) — číslo zásielky sa nedá uložiť.');
        }
        $doc = $this->api->get('order_carriers/' . $id, $ctx);
        $doc->order_carrier->tracking_number = $number;
        $this->strip($doc->order_carrier, 'order_carriers');
        $this->api->put('order_carriers', $id, $doc, $ctx);
    }

    /**
     * New delivery or invoice address for the order. @param string $type delivery | invoice
     * @param array $fields ADDRESS_FIELDS => value (only these are taken) @return int id of the new address
     */
    public function changeAddress(int $orderId, int $shopId, string $reference, string $type, array $fields): int
    {
        $col = $type === 'invoice' ? 'id_address_invoice' : 'id_address_delivery';
        $ctx = ['id_shop' => $shopId];
        $doc = $this->order($orderId, $shopId, $reference);
        $oldId = (int)$doc->order->$col;
        $a = $this->api->get('addresses/' . $oldId, $ctx);
        $addr = $a->address;
        foreach (self::ADDRESS_FIELDS as $f => $label) {
            if (array_key_exists($f, $fields) && isset($addr->$f)) {
                $addr->$f = trim((string)$fields[$f]);
            }
        }
        $addr->alias = mb_substr('Objednávka ' . $reference . ($type === 'invoice' ? ' (fakturačná)' : ''), 0, 32, 'UTF-8');
        $this->strip($addr, 'addresses', ['id', 'date_add', 'date_upd', 'deleted']);
        $new = $this->api->post('addresses', $a->asXML(), $ctx);
        $newId = (int)$new->address->id;
        if (!$newId) {
            throw new \RuntimeException('E-shop novú adresu neuložil.');
        }
        $doc = $this->order($orderId, $shopId, $reference);   // fresh copy (date_upd)
        $doc->order->$col = $newId;
        $this->strip($doc->order, 'orders', ['associations']);
        $this->api->put('orders', $orderId, $doc, $ctx);
        return $newId;
    }

    /** Removes read-only fields (the shop's schema) and the given nodes before a PUT / POST. */
    private function strip(\SimpleXMLElement $node, string $resource, array $more = []): void
    {
        if (!isset($this->readOnly[$resource])) {
            $this->readOnly[$resource] = [];
            try {
                $schema = $this->api->get($resource, ['schema' => 'synopsis']);
                foreach (($schema->children()[0] ?? null) ? $schema->children()[0]->children() : [] as $name => $f) {
                    if ((string)$f['read_only'] === 'true' || (string)$f['readOnly'] === 'true') {
                        $this->readOnly[$resource][] = $name;
                    }
                }
            } catch (\Throwable $e) {
                // no schema access: nothing known to remove
            }
        }
        foreach (array_merge($this->readOnly[$resource], $more) as $f) {
            unset($node->$f);
        }
    }
}
