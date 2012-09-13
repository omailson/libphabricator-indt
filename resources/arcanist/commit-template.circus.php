<?php
echo $title."\n\n";
if ($summary)
  echo $summary."\n\n";

echo "Differential Revision: ".$revisionID."\n\n";

echo "Signed-off-by: ".$author['name']." <".$author['email'].">\n";
foreach ($reviewers as $reviewer) {
  echo "# Reviewed-by: ".$reviewer['name']." <".$reviewer['email'].">\n";
}
if ($reviewedBy) {
  echo "Reviewed-by: ".$reviewedBy['name']." <".$reviewedBy['email'].">";
}
