<body>
<form action="bayes2smv.php" method="get">
    <label>
        Paste your bayes output here:<br/>
        <textarea name="bayes"></textarea>
    </label>
    <br/>
    <label>
        Name of label feature
        <input type="text" name="feature2decide"/>
    </label>
    <br/>
    <input type="submit" value="Gerar"/>
</form>
<hr/>
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
    return <<<SMV
MODULE {$featureName} ($params)
  VAR
    value : {{$valuesStr}};
  ASSIGN
    init(value) := {{$valuesStr}};
    next(value) := value;
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

if (isset($_GET["bayes"])) {
    if (isset($_GET["feature2decide"]) && $_GET["feature2decide"]) {
        $feature2DecideName = $_GET["feature2decide"];
        $fileContent = $_GET["bayes"];
        echo "<pre>\n";
        printModulesInput($fileContent, $feature2DecideName);
        echo "<pre/>\n";
    } else {
        echo "<H1> It's missing the name of label feature! </H1>";
    }
} else {
    echo "<pre>\n";
    echo <<<TXT
Feature: 1 Parents:
1 -0.693147
2 -0.693147
Feature: 2 Parents: 1
1 1 -0.336472
1 2 -1.25276
2 1 -1.25276
2 2 -0.336472
Feature: 3 Parents: 2
1 1 -0.336472
2 1 -1.25276
2 2 -0.154151
Feature: 4 Parents: 3
0 1 -0.693147
0 2 -0.287682
1 1 -0.693147
1 2 -1.38629
TXT;

    echo "<pre/>\n";
}
echo "</body>";
