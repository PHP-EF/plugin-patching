<?php
  $Patching = new Patching();
  $pluginConfig = $Patching->config->get('Plugins','Patching');
  if ($Patching->auth->checkAccess('ADMIN-CONFIG') == false) {
    $ib->api->setAPIResponse('Error','Unauthorized',401);
    return false;
  };

  // Get patching history
  $history = $Patching->getPatchingHistory();
  $Patching->logging->writeLog('Patching', 'Fetched history records: ' . count($history), 'debug');
  $Patching->logging->writeLog('Patching', 'History data: ' . print_r($history, true), 'debug');
  
  $content = '
  <div class="container-fluid">
    <div class="row">
      <div class="col-lg-12">
        <div class="card">
          <div class="card-body">
            <center>
              <h4>Patching History</h4>
              <p>View history of server patching jobs and their status</p>
            </center>
          </div>
        </div>
      </div>
    </div>
    <br>
    <div class="row">
      <div class="col-lg-12">
        <div class="card">
          <div class="card-body">
            <div class="container">
              <div class="row justify-content-center">
                <table class="table table-striped" id="patchingHistoryTable">
                  <thead>
                    <tr>
                      <th>Server Name</th>
                      <th>OS Type</th>
                      <th>Template</th>
                      <th>Time</th>
                      <th>Initial Status</th>
                      <th>Final Status</th>
                      <th>Job Link</th>
                    </tr>
                  </thead>
                  <tbody>';
                  
  if (empty($history)) {
    $content .= '
                    <tr>
                      <td colspan="7" class="text-center">No patching history records found</td>
                    </tr>';
  } else {
    foreach ($history as $record) {
      $finalStatus = $record['final_status'] ? $record['final_status'] : 'Pending';
      $statusClass = '';
      switch(strtolower($finalStatus)) {
        case 'successful':
          $statusClass = 'text-success';
          break;
        case 'failed':
        case 'error':
          $statusClass = 'text-danger';
          break;
        case 'pending':
          $statusClass = 'text-warning';
          break;
        default:
          $statusClass = 'text-secondary';
      }
      
      $content .= '
                      <tr>
                        <td>'.$record['server_name'].'</td>
                        <td>'.$record['os_type'].'</td>
                        <td>'.$record['template_key'].'</td>
                        <td>'.$record['timestamp'].'</td>
                        <td>'.$record['initial_status'].'</td>
                        <td class="'.$statusClass.'">'.$finalStatus.'</td>
                        <td><a href="'.$record['job_link'].'" target="_blank" class="btn btn-sm btn-primary">View Job</a></td>
                      </tr>';
    }
  }
  
  $content .= '
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
  $(document).ready(function() {
    $("#patchingHistoryTable").DataTable({
      "order": [[3, "desc"]], // Sort by timestamp by default
      "pageLength": 25,
      "responsive": true
    });
  });
  </script>';

return $content;