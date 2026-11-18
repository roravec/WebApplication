<?php
namespace Panel\Controller;

use Panel\PanelContext;
use WebApplication\Http\Request;

class DashboardController
{
    /** @var PanelContext */
    private $ctx;

    public function __construct(PanelContext $ctx)
    {
        $this->ctx = $ctx;
    }

    public function index(Request $req, array $params): string
    {
        $shop = $this->ctx->shopOrders();
        $available = $shop->available();
        return $this->ctx->page('dashboard', [
            'shopAvailable' => $available,
            'stats' => $available ? $shop->stats() : null,
            'latest' => $available ? $shop->search([], 1, 8)['rows'] : [],
        ], 'Prehľad', 'dashboard');
    }
}
