<?php
function getModule($featureName, array $values, array $parents, array $probabilities) {
    $params    = implode(", ", $parents);
    $valuesStr = implode(", ", $values);
    $probCases = array();
    foreach($probabilities as $probability) {
        $probs = array();
        foreach($probability["variables"] as $variable => $value) {
            $probs[] = $variable." = ".$value;
        }

        $probs = implode(" & ", $probs);
        $probCases[] = "(".$probs.") : ".$probability["value"].";";
    }

    $probCases = implode("\n      ",$probCases);
    if (count($values) > 1) {
        $assignStr = <<<SMV
  ASSIGN
    init(value) := {{$valuesStr}};
    next(value) := value;
SMV;
    } else {
        $assignStr = "";
    }
    return <<<SMV
MODULE {$featureName} ($params)
  VAR
    value : {{$valuesStr}};
  $assignStr
  DEFINE
    prob := case
      {$probCases}
      (TRUE) : 0;
    esac;


SMV;
}

function getProbModule($featureName, array $values, array $parents, array $probabilities) {
    $params    = implode(", ", $parents);
    //$valuesStr = implode(", ", $values);
    $probCases = array();
    foreach($probabilities as $probability) {
        $probs = array();
        foreach($probability["variables"] as $variable => $value) {
            $probs[] = $variable." = ".$value;
        }

        $probs = implode(" & ", $probs);
        $probCases[] = "(".$probs.") : ".$probability["value"].";";
    }

    $probCases = implode("\n      ",$probCases);
    return <<<SMV
MODULE {$featureName}_prob ($params, value)
  DEFINE
    prob := case
      {$probCases}
      (TRUE) : 0;
    esac;


SMV;
}


function getDecider($feature2decide, $features) {
    $feature2DecideName = $feature2decide["name"];
    $probParams = array_map(function($feature) { return $feature["name"]."_prob"; }, $features);
    $parentValues = array_map(function($parent) { return $parent."_value";}, $feature2decide["parents"]);

    $params = implode(", ", array_merge($probParams, $parentValues));
    $parentValues = implode(", ", $parentValues);
    $probParams = implode(" + ", $probParams);
    $vars = array();
    $probCases = array();
    $valueCases = array();
    foreach($feature2decide["values"] as $value) {
        $vars[] = "prob_{$value} : ". $feature2DecideName ."_prob({$parentValues}, {$value});";
        $otherValues = getOtherValues($value, $feature2decide["values"]);
        $caseConditions = array();
        foreach ($otherValues as $otherValue) {
            $caseConditions[] = "(prob_{$value}.prob >= prob_{$otherValue}.prob)";
        }

        $caseConditions = implode(" & ", $caseConditions);
        $probCases[] = "({$caseConditions}) : prob_{$value}.prob;";
        $valueCases[] = "({$caseConditions}) : {$value};";
    }

    $probCases = implode("\n      ", $probCases);
    $valueCases = implode("\n      ", $valueCases);

    $vars = implode("\n    ", $vars);
    $deciderModule = <<<SMV
MODULE decider ($params)
  VAR
    {$vars}
  DEFINE
    label := case
      {$valueCases}
    esac;
    prob := case
      {$probCases}
    esac;
    likeliest_prob := {$probParams} + prob;


SMV;
    return $deciderModule;
}

function printModulesInput($input, $feature2DecideName) {
    $features = array();
    $featuresLines = explode("\n", $input);
    $feature = array();
    $currFeatureIdx = -1;
    foreach ($featuresLines as $featuresLine) {
        $featuresLine = trim($featuresLine);
        if (!count($featuresLine)) continue;

        if (is_numeric(strpos($featuresLine, "Feature:"))) {
            $matches = array();
            preg_match("/Feature: ([a-zA-Z0-9]+) Parents:([a-zA-Z0-9, ]*)/", $featuresLine, $matches);
            $parents = array_map(function ($parent) {
                return "v_" . trim($parent, ",");
            }, array_filter(explode(" ", $matches[2]), "strlen"));

            $feature = array(
                "name" => "v_" . $matches[1],
                "values" => array(),
                "parents" => array_values($parents),
                "probabilities" => array(),
            );

            $features[] = $feature;
            $currFeatureIdx++;
        } else {
            $probabilityValues = explode(" ", $featuresLine);
            $probabilityValueIdx = count($probabilityValues) - 1;
            $variables = array(
                "value" => $probabilityValues[0]
            );
            for ($i = 1; $i < $probabilityValueIdx; $i++) {
                $parentName = $features[$currFeatureIdx]["parents"][$i - 1];
                $variables[$parentName] = $probabilityValues[$i];
            }

            $value = prepareProbValue($probabilityValues[$probabilityValueIdx]);
            $features[$currFeatureIdx]["probabilities"][] = array(
                "variables" => $variables,
                "value" => $value,
            );


            if (!in_array($probabilityValues[0], $features[$currFeatureIdx]["values"])) {
                $features[$currFeatureIdx]["values"][] = $probabilityValues[0];
            }
        }
    }

    $modules = "";
    $probs = array();
    $feature2Decide = null;
    $otherFeatures = array();
    $parentsValues = "";
    foreach ($features as $feature) {
        if ($feature["name"] != $feature2DecideName) {
            echo getModule($feature["name"], $feature["values"], $feature["parents"], $feature["probabilities"]);
            $featureName = $feature["name"];
            $parents = array_map(function ($parent) {
                return $parent . ".value";
            }, $feature["parents"]);
            $params = implode(", ", $parents);
            $modules .= "    $featureName : $featureName($params);\n";
            $probs[] = $featureName . ".prob";
            $otherFeatures[] = $feature;
        } else {
            echo getProbModule($feature["name"], $feature["values"], $feature["parents"], $feature["probabilities"]);
            $feature2Decide = $feature;
            $parentsValues = array_map(function($parent) {return $parent.".value";}, $feature["parents"]);
            $parentsValues = implode(", ", $parentsValues);
        }
    }

    echo getDecider($feature2Decide, $otherFeatures);

    $probs = implode(", ", $probs);
    $mainModule = <<<SMV
MODULE main
  VAR
$modules
    decider : decider($probs, $parentsValues);


SMV;
    echo $mainModule;
}

function getOtherValues($value, $values) {
    return array_filter($values, function($x) use ($value) {
        return $x != $value;
    });
}


function prepareProbValue($var) {
    return ceil(((float)$var) * 10000);
}

$inputFileName = isset($argv[1]) ? $argv[1] : "input.txt";
$feature2DecideName = isset($argv[2]) ? $argv[2] : "v_65";
$fileContent = file_get_contents($inputFileName);
printModulesInput($fileContent, $feature2DecideName);
