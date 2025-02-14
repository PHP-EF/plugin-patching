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
                <div id="toolbar">
                  <div class="bootstrap-table-toolbar"></div>
                </div>
                <table id="patchingHistoryTable" class="table table-striped">
                  <thead>
                    <tr>
                      <th data-field="server_name">Server Name</th>
                      <th data-field="os_type">OS Type</th>
                      <th data-field="template_key">Template</th>
                      <th data-field="timestamp" data-formatter="timestampFormatter">Time</th>
                      <th data-field="initial_status">Initial Status</th>
                      <th data-field="final_status" data-formatter="statusFormatter">Final Status</th>
                      <th data-field="job_link" data-formatter="jobLinkFormatter">Job Link</th>
                    </tr>
                  </thead>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Required Libraries -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-table@1.24.0/dist/bootstrap-table.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap-table@1.24.0/dist/bootstrap-table.min.js"></script>
  <script src="https://unpkg.com/bootstrap-table@1.24.0/dist/extensions/filter-control/bootstrap-table-filter-control.min.js"></script>
  
  <!-- Initialize table after all libraries are loaded -->
  <script>
    // Define formatters first
    function timestampFormatter(value, row, index) {
      return moment(value).format("YYYY-MM-DD HH:mm:ss");
    }

    function statusFormatter(value, row, index) {
      var statusClass = "";
      switch(value.toLowerCase()) {
        case "successful":
          statusClass = "text-success";
          break;
        case "failed":
        case "error":
          statusClass = "text-danger";
          break;
        case "pending":
          statusClass = "text-warning";
          break;
        default:
          statusClass = "text-secondary";
      }
      return "<span class=\\"" + statusClass + "\\">" + value + "</span>";
    }

    function jobLinkFormatter(value, row, index) {
      return "<a href=\\"" + value + "\\" target=\\"_blank\\" class=\\"btn btn-sm btn-primary\\">View Job</a>";
    }

    // Wait for moment.js to load before initializing
    function initTable() {
      if (typeof moment === "undefined") {
        setTimeout(initTable, 100); // Try again in 100ms
        return;
      }

      var initialData = ' . json_encode($history) . ';
      
      // Initialize table with data and configuration
      $("#patchingHistoryTable").bootstrapTable({
        "data": initialData,
        "pagination": true,
        "search": true,
        "showRefresh": true,
        "showToolbar": true,
        "toolbar": "#toolbar",
        "pageSize": 25,
        "refreshOptions": {
          "silent": true,
          "url": "/api/plugin/Patching/history",
          "method": "get"
        }
      });
    }

    // Start initialization when document is ready
    $(document).ready(function() {
      initTable();
    });
  </script>';

return $content;