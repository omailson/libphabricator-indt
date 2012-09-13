<?php
echo $title."\n\n";
if ($summary)
  echo $summary."\n\n";

if ($reviewedBy)
  echo "Reviewed by: ".$reviewedBy['name'];

foreach ($reviewers as $i => $reviewer) {
  if ($i == 0)
    echo "\n# ";
  else
    echo ", ";
  echo $reviewer['name'];
}
