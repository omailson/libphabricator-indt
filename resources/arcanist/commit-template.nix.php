<?php
echo $title."\n\n";
if ($summary)
  echo $summary."\n\n";

if ($accept_reviewers) {
  echo "Reviewed by: ";
  foreach ($accept_reviewers as $i => $reviewer) {
    if ($i !== 0)
      echo ", ";

    echo $reviewer['name'];
  }
}

foreach ($reviewers as $i => $reviewer) {
  if ($i == 0)
    echo "\n# ";
  else
    echo ", ";
  echo $reviewer['name'];
}
