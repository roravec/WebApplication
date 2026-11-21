<?php
namespace Panel\Core;

/**
 * Optional for a module: its numbered documents (quote, purchase order, production order, complaint protocol …) —
 * they get number series of the company (Nastavenia → Firmy → Číselné rady, Core\Documents::allocate) and the PDF look
 * of the company (Core\Documents::pdf). Invoices and stock documents are built in (Panel\Invoicing\Numbering).
 */
interface ProvidesDocumentTypes
{
    /** @return array type => ['label' (Slovak), 'table' (table with company_id + number), 'format' (default series format)] */
    public function documentTypes(): array;
}
