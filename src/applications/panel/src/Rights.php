<?php
namespace Panel;

/**
 * Keys of the permissions, for checks across modules ($ctx->can(Rights::INVOICES_EDIT)). The permissions themselves
 * (label, group, sensitive) are registered by the modules (Module::permissions(), Core\Permissions); who has which is
 * decided by Core\Access (roles, exceptions, companies, e-shops — docs/panel-architektura.md chapter 5).
 * Správca = panel_client.rights >= ADMIN: every permission (also of future modules), every company and e-shop.
 */
class Rights
{
    const EMPLOYEE = 10;
    const ADMIN = 100;

    const ORDERS_VIEW = 'orders.view';
    const ORDERS_EDIT = 'orders.edit';
    const INVOICES_VIEW = 'invoices.view';
    const INVOICES_EDIT = 'invoices.edit';
    const INVOICES_UNISSUE = 'invoices.unissue';
    const COMPANIES_MANAGE = 'companies.manage';
    const COMPANIES_CREATE = 'companies.create';
    const STOCK_VIEW = 'stock.view';
    const STOCK_EDIT = 'stock.edit';
    const REPORTS_VIEW = 'reports.view';   // docs/sprava-firmy-specifikacia.md
    const EXPENSES_VIEW = 'expenses.view';
    const EXPENSES_EDIT = 'expenses.edit';
    const SETTINGS_ESHOPS = 'settings.eshops';
    const SETTINGS_LEGAL = 'settings.legal';
    const SETTINGS_EMPLOYEES = 'settings.employees';
    const SETTINGS_SYSTEM = 'settings.system';
    const API_KEYS = 'api.keys';
    const TASKS_VIEW = 'tasks.view';
    const TASKS_EDIT = 'tasks.edit';
    const PROJECTS_ALL = 'projects.all';
    const PROJECTS_MANAGE = 'projects.manage';
    const TIME_TRACK = 'time.track';
    const TIME_ALL = 'time.all';

    /** Pseudo-permission: Správca only (things no role may give). */
    const ADMIN_ONLY = 'admin';
}
