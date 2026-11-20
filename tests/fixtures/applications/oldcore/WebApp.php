<?php
/** Test fixture: a page builder of the OLDEST core (SetInitVariables + GetHTML, $this->App->…), like hbkpravotice / bnhl. */
#[\AllowDynamicProperties]
class OldCoreTeam implements ICRUD
{
    public $id = 0;
    public $name = '';
    public function __construct($database, $prefix) { $this->db = $database; $this->prefix = $prefix; }
    public function Create() {}
    public function Read()
    {
        $r = $this->db->Query('SELECT name FROM ' . $this->prefix . 'text WHERE id=' . (int)$this->id);
        if ($this->db->GetNumRows($r) > 0) {
            $this->name = $r->fetch_assoc()['name'] ?? '';
        }
    }
    public function Update() {}
    public function Delete() {}
}

#[\AllowDynamicProperties]
class OldCoreWebApp   // real ported apps keep the name WebApp (one app per request; tests load several)
{
    private $defaultPage = 'overview';

    public function SetInitVariables($application, $applicationId, $applicationPath, $database, $languageCode)
    {
        $this->App = $application;
        $this->AppId = $applicationId;
        $this->AssetsPath = $applicationPath . '/assets';
        $this->Database = $database;
        $this->LanguageCode = $languageCode;
    }

    public function GetHTML($pageName)
    {
        $page = $this->App->GetContentFilename($pageName, $this->defaultPage);
        if (isset($_POST['action']) && $_POST['action'] === 'login') {
            $this->App->Login($_POST['email'], $_POST['password'], !empty($_POST['remember']));
        }
        $user = $this->App->IsLoggedIn() ? $this->App->GetUser()->GetName() . '/' . $this->App->GetUser()->GetRightsLevel() : '-';
        return implode('|', [$this->AppId, $page, $this->App->GetTextFromDatabase($this->LanguageCode, 'TITLE'), $this->AssetsPath,
            $this->App->GetDbPrefix(), $user]);
    }
}
