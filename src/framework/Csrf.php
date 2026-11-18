<?php
namespace WebApplication;

use WebApplication\Http\Request;

/**
 * Protection against cross-site form submission. Needs an active session.
 *
 *   in the form:  <?= Csrf::field() ?>
 *   on POST:      if (!Csrf::check($request)) return Response::text('Invalid form', 400);
 */
class Csrf
{
    const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="' . self::KEY . '" value="' . self::token() . '">';
    }

    /** Accepts the token from the form field or the X-CSRF-Token header (for fetch/AJAX). */
    public static function check(Request $request): bool
    {
        $sent = $request->post(self::KEY) ?? $request->header('x-csrf-token');
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($sent) && $expected !== '' && hash_equals($expected, $sent);
    }
}
