<?php
// Test regex con "1ª"
$dosis_tests = ["1ª", "2ª", "Primera", "Segunda", "primaria", "secundaria"];

foreach ($dosis_tests as $originalDosis) {
    $dosis = mb_strtolower(trim((string)$originalDosis), "UTF-8");
    $dosis = strtr($dosis, ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u"]);
    
    echo "Original: \"$originalDosis\" -> Normalizado: \"$dosis\"\n";
    
    // Test condition 1: str_contains "primera"
    $test1 = str_contains($dosis, "primera");
    echo "  str_contains(\"$dosis\", \"primera\"): " . ($test1 ? "TRUE" : "FALSE") . "\n";
    
    // Test condition 2: regex /1/
    $test2 = preg_match('/(^|\D)1(\D|$)/u', $dosis);
    echo "  preg_match('/(^|\D)1(\D|\$)/u', \"$dosis\"): " . ($test2 ? "TRUE" : "FALSE") . "\n";
    
    // Test condition 3: str_contains "segunda"
    $test3 = str_contains($dosis, "segunda");
    echo "  str_contains(\"$dosis\", \"segunda\"): " . ($test3 ? "TRUE" : "FALSE") . "\n";
    
    // Test condition 4: regex /2/
    $test4 = preg_match('/(^|\D)2(\D|$)/u', $dosis);
    echo "  preg_match('/(^|\D)2(\D|\$)/u', \"$dosis\"): " . ($test4 ? "TRUE" : "FALSE") . "\n";
    
    // Determine suffix
    $sufijo = null;
    if ($test1 || $test2) {
        $sufijo = "1";
    } elseif ($test3 || $test4) {
        $sufijo = "2";
    }
    echo "  Result: sufijo = $sufijo\n\n";
}
?>
