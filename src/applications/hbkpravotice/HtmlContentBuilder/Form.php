<?php
#[\AllowDynamicProperties]
class FormItem
{
	public $Label = "";
	public $Type;
	public $Name;
	public $Id;
	public $Form="";
	public $ClassName = "";
	public $Input = 'input';
	public $Onclick = '';
	public $Required=false;
	public $Disabled=false;
	public $Checked=false;
	public $Readonly=false;
	public $Value = "";
	public $Accesskey = "";
	public $Placeholder;
	public $Min = 0;
	public $Max = 0;
	public $Step = 0;
	public $Textarea = false;
	public $Rows = 4;
	public $Cols = 50;
	public $Maxlength=0;
	public $Size = 0;
	public $TableCompatible = true;
	public $SelectOptions = array();

	public function __construct($Type, $Name, $Value="", $Placeholder="", $Label="")
	{
		$this->Type = $Type;
		$this->Name = $Name;
		$this->Placeholder = $Placeholder;
		$this->Id = $Name;
		$this->Value = $Value;
		$this->Label = $Label;
	}

	public function GetHTML()
	{
		$html = "";
		
		/* Label handle */
		if ($this->Label != "" && $this->Type != 'hidden')
		{
			if ($this->TableCompatible && $this->Type != 'hidden') 
				$html .= '<td>';
			$html .= '<label for="'.$this->Name.'">'.$this->Label.':</label>
			';
			if ($this->TableCompatible && $this->Type != 'hidden') 
				$html .= '</td>';
		}
		if ($this->TableCompatible && $this->Type != 'hidden')
		{
			$html .= '<td>';
		}
		if ($this->Textarea)
		{
			$html .= '<textarea name="'.$this->Name.'" id="'.$this->Id.'"
			rows="'.$this->Rows.'" cols="'.$this->Cols.'"';
			if ($this->Maxlength != '')	{ $html .= ' maxlength="'.$this->Maxlength.'"'; }
			if ($this->Required)	{ $html .= ' required'; }
			if ($this->Disabled)	{ $html .= ' disabled'; }
			if ($this->Checked)		{ $html .= ' checked'; }
			if ($this->Readonly)	{ $html .= ' readonly'; }
			$html .= '>
			'.$this->Value.'
			</textarea>
			';
		}
		else if ($this->Type == 'select')
		{
			$html .= '<select name="'.$this->Name.'" id="'.$this->Id.'"';
			if ($this->ClassName != '')	{ $html .= ' class="'.$this->ClassName.'"'; }
			if ($this->Size != 0)	{ $html .= ' size="'.$this->Size.'"'; }
			if ($this->Disabled)	{ $html .= ' disabled'; }
			$html .='>';
			foreach ($this->SelectOptions as $option)
			{
				$html .= '<option value='.$option->Value;
				if ($option->Selected)
				{ $html .= ' selected'; }
				if ($option->SmallText)
				{ $html .= ' class="option-small"'; }
				$html .= '>'.$option->Name.'</option>';
			}
			$html .= '</select>';
		}
		else if ($this->Type == 'button' || $this->Type == 'submit')
		{
			if ($this->Type == 'button' && $this->Name != 'submit')
			{
				$html .= '<button type="'.$this->Type.'" ';
			}
			else
			{
				$html .= '<button type="submit" ';
			}
			if ($this->Disabled)	{ $html .= ' disabled'; }
			if ($this->Name != '')	{ $html .= ' name="'.$this->Name.'"'; }
			if ($this->Form != '')	{ $html .= ' form="'.$this->Form.'"'; }
			if ($this->Id != '')	{ $html .= ' id="'.$this->Id.'"'; }
			if ($this->Value != '')	{ $html .= ' value="'.$this->Value.'"'; }
			if ($this->Accesskey != '')	{ $html .= ' accesskey="'.$this->Accesskey.'"'; }
			if ($this->Onclick != '')	{ $html .= ' onclick="'.$this->Onclick.'"'; }
			$html .= '>'.$this->Value.'</button>';
		}
		else
		{
			$html .= '<'.$this->Input.' type="'.$this->Type.'" ';
			if ($this->Name != '')	{ $html .= ' name="'.$this->Name.'"'; }
			if ($this->ClassName != '')	{ $html .= ' class="'.$this->ClassName.'"'; }
			if ($this->Id != '')	{ $html .= ' id="'.$this->Id.'"'; }
			if ($this->Value != '')	{ $html .= ' value="'.$this->Value.'"'; }
			if ($this->Onclick != '')	{ $html .= ' onclick="'.$this->Onclick.'"'; }
			if ($this->Placeholder != ''){ $html .= ' placeholder="'.$this->Placeholder.'"'; }
			if ($this->Min != '')	{ $html .= ' min="'.$this->Min.'"'; }
			if ($this->Max != '')	{ $html .= ' max="'.$this->Max.'"'; }
			if ($this->Step != '')	{ $html .= ' step="'.$this->Step.'"'; }
			if ($this->Size != 0)	{ $html .= ' size="'.$this->Size.'"'; }
			if ($this->Maxlength != 0)	{ $html .= ' maxlength="'.$this->Maxlength.'"'; }
			if ($this->Required)	{ $html .= ' required'; }
			if ($this->Disabled)	{ $html .= ' disabled'; }
			if ($this->Checked)		{ $html .= ' checked'; }
			if ($this->Readonly)	{ $html .= ' readonly'; }
			$html .= '>' .PHP_EOL;
			if ($this->Type != 'hidden') 
			{
				//$html .= '<br>'.PHP_EOL;
			}
		}
		if ($this->TableCompatible && $this->Type != 'hidden') $html .= '</td>';
		return $html;
	}
}

#[\AllowDynamicProperties]
class SelectOption
{
	public $Name = "";
	public $Value = "";
	public $Selected = false;
	public $SmallText = false;
	public function __construct($Name, $Value, $selected=false, $smallText=false)
	{
		$this->Name = $Name;
		$this->Value = $Value;
		$this->Selected = $selected;
		$this->SmallText = $smallText;
	}
}

#[\AllowDynamicProperties]
class Form
{
	public $Action;
	public $Method;
	public $Id='';
	public $FormName;
	public $Overhead = true;
	public $InternalTable = true;
	public $SubmitBtn = true;
	public $ContentBefore="";
	public $ContentInsideStart="";
	public $ContentInsideEnd="";
	public $ContentAfter="";
	public $Notice = "";
	public $SubmitBtnText = "Submit";
	public $BrAfterDynamicItem = false;
	public $AddDynamicItemText = "Add item";
	public $Items = array();
	public $EmptyRawDynamicItem = "";
	public $DynamicItemSet = array();

	public function __construct($Action, $Method)
	{
		$this->Action = $Action;
		$this->Method = $Method;
	}

	public function GetHTML()
	{
		$html = "";
		if ($this->Overhead)
		{
			$html .= '<section class="form">'.$this->ContentBefore;
		}
		$html .= '<form action="'.$this->Action.'" method="'.$this->Method.'"';
		if ($this->Id != '')
		{
			$html .= ' id="'.$this->Id.'"';
		}
		$html .= '>';
		$html .= $this->ContentInsideStart;
		if ($this->Overhead)
		{
			$html .= '<h4>'.$this->FormName."</h4>";
		}
		if ($this->Notice != "")
		{
			$html .= '<span style="color:red;">'.$this->Notice."</span><br><br>";
		}
		if ($this->InternalTable )
		{
			$html .= '<table>';
		}
		foreach ($this->Items as $item) 
		{
			if ($this->InternalTable )
				$html .= '<tr>';
			$html .= $item->GetHTML();
			if ($this->InternalTable )
				$html .= '</tr>';
		}
		if (count($this->DynamicItemSet) > 0)
		{
			$html .= '<div id="item-container">';
			$html .= '</div>';
		}
		$html .= $this->ReadDynamicItems();
		if ($this->SubmitBtn)
		{
			
			if ($this->InternalTable )
				$html .= '<td>';
			$html .= '<button type="submit" value="Submit">'.$this->SubmitBtnText.'</button>';
			if ($this->InternalTable )
				$html .= '</td>';
		}
		if ($this->InternalTable )
		{
			$html .= '</table>';
		}
		$html .= $this->ContentInsideEnd;
		$html .= '
		</form>'.$this->ContentAfter;
		if ($this->Overhead)
		{
			$html .= '</section>';
		}

		$html .= $this->GetDynamicItemsScript();
		return $html;
	}

	public function ReadDynamicItems()
	{
		$html = '';
		if (count($this->DynamicItemSet) > 0)
		{
			$html .= '<button type="button" onclick="addNewItem()">'.$this->AddDynamicItemText.'</button><br><br>
			';
		}
		return $html;
	}

	public function GetDynamicItemsScript()
	{
		$html = '';
		if (count($this->DynamicItemSet) > 0)
		{
			$this->EmptyRawDynamicItem = '';
			$i = 0;
			foreach ($this->DynamicItemSet as $item) 
			{
				$item->Id .= $i;
				$item->Name .= '[]';
				$this->EmptyRawDynamicItem .= $item->GetHTML();
				if ($this->BrAfterDynamicItem)
					$this->EmptyRawDynamicItem .= '<br>';
				$i++;
			}
		}

		if ($this->EmptyRawDynamicItem != "")
		{
			$html .= '
		<script>
    let itemCount = 1;

    function addNewItem()
	{
      	itemCount++;

      	const container = document.getElementById("item-container");
      	const newItem = document.createElement("div");
      	newItem.className = "form-group";

      	newItem.innerHTML = `
		';
			$html .= $this->EmptyRawDynamicItem;
			$html .= '<button type="button" onclick="removeItem(this)">Remove</button>';
			$html .= '
		`;

    	container.appendChild(newItem);
    }

    function removeItem(button)
	{
      	const container = document.getElementById("item-container");
      	const itemToRemove = button.parentNode;
      	container.removeChild(itemToRemove);
    }
	</script>
		';
		}
		return $html;
	}

	public function AddDynamicItem($item)
	{
		$this->DynamicItemSet[] = $item;
	}

	public function AddItem($item)
	{
		$this->Items[] = $item;
	}
}
?>