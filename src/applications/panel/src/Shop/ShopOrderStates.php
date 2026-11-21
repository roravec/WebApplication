<?php
namespace Panel\Shop;

/**
 * Order states of a shop, read and changed IN THE SHOP through its Webservice (`order_states` GET / PUT / POST) — owner
 * 2026-11-21: "urob tú úpravu stavov v paneli" (a state without send_email sent no e-mail on a state change).
 * Like Objednávky → Stavy in the PrestaShop admin: name and e-mail template (in the panel's language, Slovak), colour,
 * the flags below. Invoice flags (invoice, pdf_invoice) are not offered — the shops don't issue invoices (the panel
 * does) — and stay as they are; module_name / unremovable / deleted are never touched; states are not deleted here.
 */
class ShopOrderStates extends ShopOrderEdit
{
    /** flag => label (PrestaShop admin wording) */
    const FLAGS = [
        'send_email' => 'Poslať zákazníkovi e-mail, keď objednávka prejde do tohto stavu',
        'paid' => 'Objednávka je zaplatená',
        'shipped' => 'Objednávka je odoslaná',
        'delivery' => 'Zobraziť dodací list (PDF)',
        'pdf_delivery' => 'Priložiť dodací list k e-mailu',
        'logable' => 'Považovať objednávku za platnú (štatistiky, tržby)',
        'hidden' => 'Skryť tento stav zákazníkovi (v jeho účte)',
    ];
    /** e-mail templates of PrestaShop itself (mails/<lang>/) — offered together with those the states use */
    const STANDARD_TEMPLATES = ['payment', 'shipped', 'in_transit', 'preparation', 'order_canceled', 'refund', 'payment_error',
        'outofstock', 'bankwire', 'cheque', 'cashondelivery'];

    /** States that are not deleted: id => [id, name, template, color, flags…, unremovable, module_name], sorted by name. */
    public function all(int $langId): array
    {
        $out = [];
        foreach ($this->api->get('order_states', ['display' => 'full'])->order_states->order_state ?? [] as $s) {
            if ((int)$s->deleted === 1) {
                continue;
            }
            $row = ['id' => (int)$s->id, 'name' => PrestaShopApi::text($s->name, $langId), 'template' => PrestaShopApi::text($s->template, $langId),
                'color' => (string)$s->color, 'unremovable' => (int)$s->unremovable === 1, 'module_name' => (string)$s->module_name];
            foreach (self::FLAGS as $f => $label) {
                $row[$f] = (int)$s->$f === 1;
            }
            $out[$row['id']] = $row;
        }
        uasort($out, function ($a, $b) { return strcmp(ShopStock::sortKey($a['name']), ShopStock::sortKey($b['name'])); });
        return $out;
    }

    /** E-mail templates for the picker: the standard ones + those the shop's states use. */
    public static function templates(array $states): array
    {
        $t = array_merge(self::STANDARD_TEMPLATES, array_column($states, 'template'));
        $t = array_values(array_unique(array_filter($t, 'strlen')));
        sort($t);
        return $t;
    }

    /** Error messages for the form values (name, template, color, flags) — empty = valid. */
    public static function errors(array $v): array
    {
        $err = [];
        if ($v['name'] === '' || mb_strlen($v['name'], 'UTF-8') > 64 || preg_match('/[<>={}]/', $v['name'])) {
            $err['name'] = 'Zadajte názov (najviac 64 znakov, bez znakov < > = { }).';
        }
        if ($v['template'] !== '' && !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $v['template'])) {
            $err['template'] = 'Šablóna: len písmená bez diakritiky, číslice, _ a - (názov súboru e-mailu v e-shope).';
        }
        if (!empty($v['send_email']) && $v['template'] === '') {
            $err['template'] = 'Na posielanie e-mailu vyberte šablónu.';
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v['color'])) {
            $err['color'] = 'Farba v tvare #rrggbb.';
        }
        return $err;
    }

    /**
     * Saves a state in the shop. @param int $id 0 = new state @param array $v name, template, color, FLAGS => bool
     * @param int $langId the language the panel shows (the name / template of the other languages stay; a new state gets
     *                    them in every language) @return int id of the state
     */
    public function save(int $id, array $v, int $langId): int
    {
        $doc = $id ? $this->api->get('order_states/' . $id) : $this->api->get('order_states', ['schema' => 'blank']);
        $s = $doc->order_state;
        if ($id && (int)$s->id !== $id) {
            throw new \RuntimeException('Stav ' . $id . ' sa v e-shope nenašiel.');
        }
        foreach (['name' => $v['name'], 'template' => $v['template']] as $field => $value) {
            $langs = isset($s->$field->language) ? $s->$field->language : [];
            $set = 0;
            foreach ($langs as $l) {
                if (!$id || $langId === 0 || (int)$l['id'] === $langId) {
                    dom_import_simplexml($l)->textContent = $value;   // escapes & < > properly
                    $set++;
                }
            }
            if (!$set) {
                throw new \RuntimeException('E-shop nemá jazyk ' . $langId . ' pre pole ' . $field . '.');
            }
        }
        $s->color = $v['color'];
        foreach (self::FLAGS as $f => $label) {
            $s->$f = !empty($v[$f]) ? '1' : '0';
        }
        if (!$id) {   // a new state: the invoice flags off, nothing deleted / unremovable / of a module
            foreach (['invoice', 'pdf_invoice', 'deleted', 'unremovable'] as $f) {
                $s->$f = '0';
            }
        }
        $this->strip($s, 'order_states', $id ? ['associations'] : ['id', 'associations']);
        $res = $id ? $this->api->put('order_states', $id, $doc) : $this->api->post('order_states', $doc->asXML());
        $saved = (int)$res->order_state->id ?: $id;
        if (!$saved || (int)$res->order_state->send_email !== (!empty($v['send_email']) ? 1 : 0)) {
            throw new \RuntimeException('E-shop stav neuložil (alebo vrátil iné hodnoty).');
        }
        return $saved;
    }
}
