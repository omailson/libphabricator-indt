<?php
# Commit template for arc tect
#
# Preview:
#   Short summary
#
#   Extended summary. Here the developer can explain better what he/she intended
#   with this patch
#
#   Differential Revision: http://phabricator.company.com/D300
#   Signed-off-by: John Doe <johndoe@company.com>
#   # Reviewed-by: Reviewer Who Is Commented-out <reviewer3@company.com>
#   # Reviewed-by: Reviewer Who Is Commented-out <reviewer4@company.com>
#   Reviewed-by: Reviewer Who Accepted <reviewer1@company.com>
#   Reviewed-by: Another Reviewer von Accepted <reviewer2@company.com>
#
# Version: 2.0

function get_microsoft_email($user) {
  foreach ($user['emails'] as $email) {
    if (strpos($email,'@microsoft.com') !== false) {
      return $email;
	}
  }
  throw new Exception("The user ".$user['name']." has not set a Microsoft email.");
}

echo $title."\n\n";
if ($summary)
  echo $summary."\n\n";

echo "Differential Revision: ".$revisionID."\n\n";

echo "Signed-off-by: ".$author['name']." <".get_microsoft_email($author).">\n";
foreach ($reviewers as $reviewer) {
  echo "# Reviewed-by: ".$reviewer['name']." <".get_microsoft_email($reviewer).">\n";
}

foreach ($accept_reviewers as $reviewer) {
  echo "Reviewed-by: ".$reviewer['name']." <".get_microsoft_email($reviewer).">\n";
}
