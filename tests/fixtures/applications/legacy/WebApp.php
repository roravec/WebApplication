<?php
/** Test fixture: an old-style app (IWebApp) using the legacy database API, texts, echo and http_response_code. */
class WebApp implements IWebApp
{
    public $name = '';

    public function get($rootApplication): string
    {
        $page = $rootApplication->getUriSegment(0) ?? 'home';
        if ($page === 'missing') {
            http_response_code(404);
            return 'not here';
        }
        if ($page === 'items') {
            $db = $rootApplication->getLegacyDatabase();
            $res = $db->Query("SELECT id, name FROM " . $rootApplication->GetDbPrefix() . "client WHERE name LIKE '%" . $db->EscapeString("O'Brien") . "%'");
            $out = [];
            while ($row = $res->fetch_assoc()) {
                $out[] = gettype($row['id']) . ':' . $row['name'];
            }
            return implode(',', $out) . '|' . $db->GetNumRows($res);
        }
        echo 'echoed+';
        return $rootApplication->getTextFromDatabase('sk', 'TITLE') . '|' . $this->name;
    }
    public function post($rootApplication): string { return 'posted'; }
    public function put($rootApplication): string { return $this->methodNotAllowed('PUT', $rootApplication); }
    public function delete($rootApplication): string { return $this->methodNotAllowed('DELETE', $rootApplication); }
    public function methodNotAllowed(string $method, $rootApplication): string
    {
        $rootApplication->response()->setStatus(405);
        return 'no ' . $method;
    }
}
