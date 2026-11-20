<?php
include_once __DIR__ . "/Form.php";

#[\AllowDynamicProperties]
class FormGenerator {
    /*public static function generateForm($class) {
        $form = '<form action="process_form.php" method="post">' . PHP_EOL;

        foreach (get_class_vars($class) as $property => $default) {
            $form .= '    <label for="' . $property . '">' . ucfirst($property) . ':</label>' . PHP_EOL;

            // Determine the input type based on the data type of the property
            $inputType = self::getInputType($default);
            
            $form .= '    <input type="' . $inputType . '" id="' . $property . '" name="' . $property . '">' . PHP_EOL;
        }

        $form .= '    <input type="submit" value="Submit">' . PHP_EOL;
        $form .= '</form>';

        return $form;
    }*/

	public static function GenerateForm($class, $action, $method) 
	{
        $form = new Form($action, $method);
		foreach (get_class_vars($class) as $property => $default)
		{
			$inputType = self::getInputType($default);
			$item = new FormItem($inputType, );
			$form->AddItem($item);
		}
		return $form;
    }

    private static function GetInputType($value) {
        // Determine the input type based on the data type of the property
        if (is_numeric($value)) {
            return 'number';
        } else {
            return 'text';
        }
    }
}

#[\AllowDynamicProperties]
class SampleForm {
    public $name;
    public $age;
    public $email;
}

// Generate the form for the SampleForm class
$htmlForm = FormGenerator::generateForm('SampleForm');

// Output the generated form
echo $htmlForm;
?>