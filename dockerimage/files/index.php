<?php

// Write the raw input to the log file...
$logFile = fopen("logs/jiraService.log", "a") or die("Unable to open file!");

$jiraBaseURL = getenv("JIRA_BASE_URL");
$jiraUsername = getenv("JIRA_USERNAME");
$jiraAPIToken = getenv("JIRA_API_TOKEN");
$jiraProjectKey = getenv("JIRA_PROJECT_KEY");
$jiraIssueType = getenv("JIRA_ISSUE_TYPE");
$jiraTicketForProblems = getenv("JIRA_TICKET_FOR_PROBLEMS") === 'true'? true : false;
$jiraTicketForEvaluations = getenv("JIRA_TICKET_FOR_EVALUATIONS") === 'true'? true : false;
$dynatraceTenant = getenv("DT_TENANT");

if ($jiraBaseURL == null || $jiraUsername == null || $jiraAPIToken == null || $jiraProjectKey == null || $jiraIssueType == null) {
    fwrite($logFile, "Missing mandatory input parameters JIRA_BASE_URL and / or JIRA_USERNAME and / or JIRA_API_TOKEN and / or JIRA_PROJECT_KEY and / or JIRA_ISSUE_TYPE");
    exit("Missing mandatory input parameters JIRA_BASE_URL and / or JIRA_USERNAME and / or JIRA_API_TOKEN and / or JIRA_PROJECT_KEY and / or JIRA_ISSUE_TYPE");
}

fwrite($logFile, "Got all input variables. Proceeding.\n");

if ($jiraTicketForProblems) fwrite($logFile, "Will create tickets for problems.\n");
else fwrite($logFile, "Will NOT create tickets for problems.\n");
if ($jiraTicketForEvaluations) fwrite($logFile, "Will create tickets for evaluations.\n");
else fwrite($logFile, "Will NOT create tickets for evaluations.\n");

$entityBody = file_get_contents('php://input');

if ($entityBody == null) {
  fwrite($logFile, "Missing data input from Keptn. Exiting.");
  exit("Missing data input from Keptn. Exiting.");
}

//Decode the incoming JSON event
$cloudEvent = json_decode($entityBody);

$eventType = $cloudEvent->{'type'};

fwrite($logFile, "Event Type: $eventType \n");

// Only problem events have a state, so check event state only when it's a problem.open event.
$eventState = "";
if ($eventType == "sh.keptn.event.problem.open") $eventState = $cloudEvent->{'data'}->{'State'};

if ($eventType == "sh.keptn.event.problem.open") fwrite($logFile, "Problem Event State (this can be CLOSED or OPEN): $eventState\n");

/************************************************************
   INPUT PARAM PROCESSING END. START FUNCTION DEFINITIONS.
*************************************************************/

function createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, $logFile) {
    
    $jiraBaseURL = "$jiraBaseURL/rest/api/2/issue";
    
    $payload = json_encode($jiraTicketObj);
    
    // Base64 encode the JIRA username and password
    $encodedKey = base64_encode($jiraUsername . ':' . $jiraAPIToken);

    $ch = curl_init($jiraBaseURL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    
    // Set HTTP Header for POST request
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
      'Content-Type: application/json',
      'User-Agent: keptn-jira-service/v1',
      "Authorization: Basic $encodedKey"
    ));
    
    // Submit the POST request
    $result = curl_exec($ch);

    fwrite($logFile,"Result: $result\n");
    // Close cURL session handle
    curl_close($ch);
}

/*************************************************
*    CREATE TICKET FOR PROBLEM OPEN EVENT
*************************************************/

if ($jiraTicketForProblems && $eventType == "sh.keptn.event.problem.open" && $eventState == "OPEN") {
    
    // Create a JIRA ticket.
    fwrite($logFile, "Got a problem opening event. Creating JIRA ticket.\n");
    
    $eventProblemTitle = $cloudEvent->{'data'}->{'ProblemTitle'};
    $eventImpactedEntity = $cloudEvent->{'data'}->{'ImpactedEntity'};
    $keptnProject = $cloudEvent->{'data'}->{'project'};
    $keptnService = $cloudEvent->{'data'}->{'service'};
    $keptnStage = $cloudEvent->{'data'}->{'stage'};
    $keptnContext = $cloudEvent->{'shkeptncontext'};
    $eventProblemDetails = $cloudEvent->{'data'}->{'ProblemDetails'};
    $eventPID = $cloudEvent->{'data'}->{'PID'};

    $eventProblemID = $cloudEvent->{'data'}->{'ProblemID'};
    $eventTime = $cloudEvent->{'time'};
    $eventTags = "";
    $eventTagsArray = array();

    if ($cloudEvent->{'data'}->{'Tags'} != null)  {
      // Build event tags array by splitting on comma
      $eventTagsArray = explode(',', $eventTags);
    }
    
    fwrite($logFile,"Finished processing problem inputs. Creating JIRA JSON now.\n");
    
    // Build JSON for JIRA
    $jiraTicketObj = new stdClass();
    $jiraTicketObj->fields->project->key = $jiraProjectKey;
    $jiraTicketObj->fields->summary = "[PROBLEM] $eventProblemTitle";
    $jiraTicketObj->fields->description = ""; // Ticket Body goes here...
    $jiraTicketObj->fields->issuetype->name = $jiraIssueType;
    $jiraTicketObj->fields->description .= "$eventImpactedEntity\n\n";

    // Add keptn_* labels
    $labels = array();
    if ($keptnProject != null) {
      $jiraTicketObj->fields->description .= "*Keptn Details*\n";
      $jiraTicketObj->fields->description .= "Project: $keptnProject\n";
      array_push($labels, "keptn_project:$keptnProject");
    }
    // Add keptn_service label, if present, to the ticket body and as a JIRA label.
    if ($keptnService != null) {
      $jiraTicketObj->fields->description .= "Service: $keptnService\n";
      array_push($labels, "keptn_service:$keptnService");
    }
    // Add keptn_stage label, if present to the ticket body and as a JIRA label.
    if ($keptnStage != null) {
      $jiraTicketObj->fields->description .= "Stage: $keptnStage\n";
      array_push($labels, "keptn_stage:$keptnStage");
    }
    
    // "labels" can be passed via JSON. Add all labels as jira labels
    $labelsFromJSON = $cloudEvent->{'data'}->{'labels'};
    if ($labelsFromJSON != null) {
      foreach ($labelsFromJSON as $key => $value) {
        if (is_bool($value)) $value = var_export($value, true); // Transform boolean to string.
        // JIRA doesn't accept whitespace in labels. Replace whitespace with dashes
        $key = str_replace(' ', '-', $key);
        $value = str_replace(' ', '-', $value);
        
        array_push($labels,"$key:$value");
      
        // Special processing for a label of "jira-issue" key.
        // Link issues with 'is caused by' if a parent issue ID exists
        // TODO
      }
    }
    
    
    // Add labels to JIRA Object
    if (count($labels) > 0) {
      $jiraTicketObj->fields->labels = $labels;
    }
    
    // Print problem details
    $jiraTicketObj->fields->description .= "\n*Problem Details*\n";
    if (is_string($eventProblemDetails)) $jiraTicketObj->fields->description .= "$eventProblemDetails \n";
    else {
      foreach ($eventProblemDetails as $key => $value) {
        if (is_bool($value)) {
          $value = var_export($value, true); // Transform boolean to string.
        }
        // Ignore certain fields.
        $ignore_fields = array("startTime", "endTime", "status", "displayName");
        if (in_array($key, $ignore_fields)) continue;
        
        $jiraTicketObj->fields->description .= "$key: $value\n";
      }
    }
   
    // If there are dynatrace tags, pass as a table.
    if (sizeof($eventTagsArray) > 1) {
        $jiraTicketObj->fields->description .= "*Tags*\n";
        $jiraTicketObj->fields->description .= "{noformat}";
        
        foreach ($eventTagsArray as $tag) {
            $jiraTicketObj->fields->description .= "$tag\n";
        }
        $jiraTicketObj->fields->description .= "{noformat}\n";
    }
    
    $jiraTicketObj->fields->description .= "\n*Additional Information*\n";
    $jiraTicketObj->fields->description .= "Problem ID: $eventProblemID\n";
    $jiraTicketObj->fields->description .= "PID: $eventPID\n";
    $jiraTicketObj->fields->description .= "Keptn Context: $keptnContext\n";
    $jiraTicketObj->fields->description .= "Event Time: $eventTime\n";
    
    /* If a dynatrace is used, add a link to the problem ticket.
     * The official JIRA plugin uses this for all sorts of extended functionality
     */
    if ($dynatraceTenant) {
      $dynatraceLink = "https://$dynatraceTenant/#problems/problemdetails;pid=$eventPID";
      $jiraTicketObj->fields->description .= "Dynatrace: $dynatraceLink";
    }

    fwrite($logFile, "Completed Event processing. Creating ticket now. \n");

    // POST DATA TO JIRA
    createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, $logFile);
}

/*************************************************
*    MODIFY TICKET FOR PROBLEM CLOSED EVENT
*************************************************/

if ($jiraTicketForProblems && $eventType == "sh.keptn.event.problem.open" && $eventState == "CLOSED") {
    
    // Modify JIRA ticket.
    fwrite($logFile, "Got a problem closed event. NOT YET IMPLEMENTED. \n");
    fwrite($logFile, "$entityBody \n");
    // NOT YET IMPLEMENTED
    // Problem is closing. Process the JIRA ticket.
}

/*************************************************
*  CREATE TICKET FOR PERFORMANCE EVALUATION EVENT
*************************************************/

if ($jiraTicketForEvaluations && $eventType == "sh.keptn.events.evaluation-done") {
    
    // Create JIRA ticket for performance evaluation.
    fwrite($logFile, "Got an evaluation-done event. Create a JIRA ticket. \n");
    
    // Transform Keptn Evaluation Result to uppercase
    $resultLowercase = $cloudEvent->{'data'}->{'result'};
    $result = strtoupper($cloudEvent->{'data'}->{'result'});

    $keptnProject = $cloudEvent->{'data'}->{'project'};
    $keptnService = $cloudEvent->{'data'}->{'service'};
    $keptnStage = $cloudEvent->{'data'}->{'stage'};
    $keptnContext = $cloudEvent->{'shkeptncontext'};

    fwrite($logFile,"Finished processing problem inputs. Creating JIRA JSON now.\n");
    
    // Build JSON for JIRA
    $jiraTicketObj = new stdClass();
    $jiraTicketObj->fields->project->key = $jiraProjectKey;
    $jiraTicketObj->fields->summary = "[EVALUATION] $keptnProject - $keptnService - $keptnStage Result: $result";
    $jiraTicketObj->fields->description = ""; // Ticket Body goes here...
    $jiraTicketObj->fields->issuetype->name = $jiraIssueType;
    
    // Add keptn_* labels
    $labels = array();
    if ($keptnProject != null) array_push($labels, "keptn_project:$keptnProject");
    
    // Add keptn_project label, if present to the ticket body and as a JIRA label.
    if ($keptnService != null) array_push($labels, "keptn_service:$keptnService");
    
    // Add keptn_project label, if present to the ticket body and as a JIRA label.
    if ($keptnStage != null) array_push($labels, "keptn_stage:$keptnStage");
    
    // Create keptn_result label to show "pass", "warning" or "fail" as a label.
    array_push($labels,"keptn_result:$resultLowercase");
    
    // "labels" can be passed via JSON. Add all labels as jira labels
    $labelsFromJSON = $cloudEvent->{'data'}->{'labels'};
    if ($labelsFromJSON != null) {
      foreach ($labelsFromJSON as $key => $value) {
        if (is_bool($value)) $value = var_export($value, true); // Transform boolean to string.
        // JIRA doesn't accept whitespace in labels. Replace whitespace with dashes
        $key = str_replace(' ', '-', $key);
        $value = str_replace(' ', '-', $value);
        array_push($labels,"$key:$value");
      
        // Special processing for a label of "jira-issue" key.
        // Link issues with 'is caused by' if a parent issue ID exists
        // TODO
      }
    }
    
    // Add labels to JIRA Object
    if (count($labels) > 0) {
      $jiraTicketObj->fields->labels = $labels;
    }
    
    // For loop through indicatorResults
    $jiraTicketObj->fields->description .= "*SLI Results*\n";
    foreach ($cloudEvent->{'data'}->{'evaluationdetails'}->{'indicatorResults'} as &$value) {
      $jiraTicketObj->fields->description .= "|| *Metric* || *Status* || *Value* ||\n";
      $jiraTicketObj->fields->description .= "| " . $value->{'value'}->{'metric'} . " | " . $value->{'status'} . " | " . $value->{'value'}->{'value'} ." |\n\n";
      $jiraTicketObj->fields->description .= "*Targets*\n\n";
      $jiraTicketObj->fields->description .= "|| *Criteria* || *Violated* ||\n";
  
      foreach ($value->{'targets'} as &$target) {
        $jiraTicketObj->fields->description .= "| " . $target->{'criteria'} . " | " . ($target->{'violated'} ? 'true' : 'false') ." |\n";
      }
  
      if ($value->{'value'}->{'message'} != "") {
        $jiraTicketObj->fields->description .= "Message: " . $value->{'value'}->{'message'} . "\n\n";
      }
    }

    $jiraTicketObj->fields->description .= "Keptn Context: $keptnContext";
    
    fwrite($logFile, "Completed Event processing. Creating ticket now. \n");
    
    // POST DATA TO JIRA
    createJIRATicket($jiraBaseURL, $jiraUsername, $jiraAPIToken, $jiraTicketObj, $logFile);
}

// Close handle to log file
fclose($logFile);
?>