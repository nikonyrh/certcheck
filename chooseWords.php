<?php
$all = array_flip(array_map(function ($str) {
	$str = strtolower(trim($str));
	return preg_match('/^well-/', $str) ? '_remove' : $str;
}, file('words_all.txt')));

if (isset($all['_remove'])) {
	unset($all['_remove']);
}

//die(print_r($all, true));

$words  = array();
$nWords = isset($argv[1]) ? intval($argv[1]) : 10;

$first  = '';
$maxLen = 0;

foreach (array_keys($all) as $word) {
	$len = strlen($word);
	if ($len > $maxLen) {
		$maxLen = $len;
		$first  = $word;
	}
}

$words[] = $first;
unset($all[$first]);

while (sizeof($words) < $nWords) {
	$bestWord = '';
	$score    = 0;
	
	foreach (array_keys($all) as $newWord) {
		$minScore = 10000;
		foreach ($words as $word) {
			$minScore = min($minScore, levenshtein($newWord, $word));
		}
		
		if ($minScore > $score) {
			$score    = $minScore;
			$bestWord = $newWord;
			//echo "New best: $bestWord @ $score\n";
		}
	}
	
	//echo "Chose: $bestWord\n\n";
	$words[] = $bestWord;
	unset($all[$bestWord]);
}

sort($words);
$words[] = '';
echo implode("\n", $words);
