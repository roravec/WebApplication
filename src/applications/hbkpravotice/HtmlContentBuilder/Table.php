<?php
#[\AllowDynamicProperties]
class TableRow
{
	private $Columns = array();
	public $RawRow = "";
	public $Thead = false;

	public function __construct(
		$col0="", $col1= "", $col2= "", $col3="", $col4= "", 
		$col5= "", $col6="", $col7= "", $col8= "", $col9="", 
		$col10= "", $col11= "")
	{
		if ($col0 != "") $this->AddColumn($col0);
		if ($col1 != "") $this->AddColumn($col1);
		if ($col2 != "") $this->AddColumn($col2);
		if ($col3 != "") $this->AddColumn($col3);
		if ($col4 != "") $this->AddColumn($col4);
		if ($col5 != "") $this->AddColumn($col5);
		if ($col6 != "") $this->AddColumn($col6);
		if ($col7 != "") $this->AddColumn($col7);
		if ($col8 != "") $this->AddColumn($col8);
		if ($col9 != "") $this->AddColumn($col9);
		if ($col10 != "") $this->AddColumn($col10);
		if ($col11 != "") $this->AddColumn($col11);
	}

	public function AddColumn($col)
	{
		$this->Columns[] = $col;
	}

	public function GetHTML()
	{
		if ($this->RawRow != '')
		{
			return $this->RawRow;
		}
		$html = "";

		/*********************** */
		//$html .= "<tr>";
		foreach($this->Columns as $col)
		{
			$html .= '<td>'.$col.'</td>';
		}
		//$html .= "</tr>";
		/*********************** */
		return $html;
	}
}

#[\AllowDynamicProperties]
class Table
{
	public $FontSize = 0;
	public $Id = '';
	public $tbodyId = "";
	public $Rows = array();
	public $NoTbodyTag = false;
	public $AddContentBefore = "";
	public $AddContentAfter = "";
	public $Overhead = true;
	public $ForceBorders = false;
	public $tbodyStarted = false;

	public function __construct()
	{
	}

	public function GetHTML()
	{
		$html = "";
		if ($this->ForceBorders)
		{
			$html .= '<style>
			table, th, td {
			  border: 1px solid black;
			  border-collapse: collapse;
			}
			</style>';
		}
		if ($this->Overhead)
			$html .= '<section class="table">';
		$html .= $this->AddContentBefore;
		$html .= '<table style="';
		if ($this->FontSize > 0)
		{
			$html .= 'font-size:'.$this->FontSize.'px;';
		}
		$html .= '"';
		if ($this->Id != '')
			$html .= ' id="'.$this->Id.'"';
		$html .= '>';
		foreach ($this->Rows as $row) 
		{
			if ($row->Thead) $html .= "<thead>";
			else if (!$this->tbodyStarted && !$this->NoTbodyTag) 
			{
				$this->tbodyStarted = true;
				$html .= "<tbody";
				if ($this->tbodyId != "")
					$html .= " id=".$this->tbodyId;
				$html .= ">";
			}
			$html .= "<tr>";
			$html .= $row->GetHTML();
			$html .= "</tr>";
			if ($row->Thead) $html .= "</thead>";
		}
		if ($this->tbodyStarted && !$this->NoTbodyTag) $html .= "</tbody>";
		$html .= "</table>".$this->AddContentAfter;
		if ($this->Overhead)
			$html .= '</section>';
		return $html;
	}

	public function AddRow($item)
	{
		$this->Rows[] = $item;
	}
}
?>