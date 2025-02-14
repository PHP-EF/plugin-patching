<?php
//// Everything after this line (2) is Core Functionality and no changes are permitted until after line (188).
// **
// USED TO DEFINE PLUGIN INFORMATION & CLASS
// **

//Allow Higher Memory Limit for PHP
// ini_set("memory_limit","512M");

// PLUGIN INFORMATION - This should match what is in plugin.json
$GLOBALS['plugins']['Patching'] = [ // Plugin Name
    'name' => 'Patching', // Plugin Name
    'author' => 'TinyTechLabUK', // Who wrote the plugin
    'category' => 'Server', // One to Two Word Description
    'link' => 'https://github.com/PHP-EF/plugin-patching', // Link to plugin info
    'version' => '1.0.0', // SemVer of plugin
    'image' => 'logo.png', // 1:1 non transparent image for plugin
    'settings' => true, // does plugin need a settings modal?
    'api' => '/api/plugin/Patching/settings', // api route for settings page, or null if no settings page
    'requires' => ['awx'] // Add AWX as a requirement
];

trait PatchingTrait {
    private $patchingpluginConfig;
    
    public function initializePatchingConfig() {
        $this->patchingpluginConfig = $this->config->get('Plugins','Patching') ?? [];
    }
}

trait CMDBTrait {
    private $sql;
    
    public function initializeCMDB() {
        $dbFile = dirname(__DIR__,2). DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cmdb.db';
        $this->sql = new PDO("sqlite:$dbFile");
        $this->sql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->_createPatchingHistoryTable();
    }

    /**
     * Create the patching history table if it doesn't exist
     * @return void
     */
    private function _createPatchingHistoryTable() {
        // Only create if it doesn't exist
        $this->sql->exec("CREATE TABLE IF NOT EXISTS patching_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            server_name TEXT NOT NULL,
            job_id TEXT NOT NULL,
            job_link TEXT NOT NULL,
            template_id TEXT NOT NULL,
            template_key TEXT NOT NULL,
            initial_status TEXT NOT NULL,
            os_type TEXT NOT NULL,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_checked DATETIME,
            final_status TEXT
        )");
        
        $this->logging->writeLog('Patching', 'Ensured patching_history table exists', 'debug');
    }

    /**
     * Verify the patching history table structure
     * @return array Results of verification
     */
    public function verifyDatabaseStructure() {
        try {
            // Get table info
            $stmt = $this->sql->query("PRAGMA table_info(patching_history)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Expected columns and their types
            $expectedColumns = [
                'id' => 'INTEGER',
                'server_name' => 'TEXT',
                'job_id' => 'TEXT',
                'job_link' => 'TEXT',
                'template_id' => 'TEXT',
                'template_key' => 'TEXT',
                'initial_status' => 'TEXT',
                'os_type' => 'TEXT',
                'timestamp' => 'DATETIME',
                'last_checked' => 'DATETIME',
                'final_status' => 'TEXT'
            ];
            
            // Check if all expected columns exist with correct types
            $missingColumns = [];
            $wrongTypes = [];
            $existingColumns = [];
            
            foreach ($columns as $column) {
                $existingColumns[$column['name']] = $column['type'];
            }
            
            foreach ($expectedColumns as $name => $type) {
                if (!isset($existingColumns[$name])) {
                    $missingColumns[] = $name;
                } elseif ($existingColumns[$name] !== $type) {
                    $wrongTypes[] = [
                        'column' => $name,
                        'expected' => $type,
                        'found' => $existingColumns[$name]
                    ];
                }
            }
            
            $isValid = empty($missingColumns) && empty($wrongTypes);
            
            return [
                'success' => true,
                'data' => [
                    'valid' => $isValid,
                    'missing_columns' => $missingColumns,
                    'wrong_types' => $wrongTypes,
                    'existing_columns' => $existingColumns
                ]
            ];
        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error verifying database structure: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Recreate the patching history table
     * @return array Results of recreation
     */
    public function recreateDatabaseStructure() {
        try {
            $this->_createPatchingHistoryTable();
            return [
                'success' => true,
                'message' => 'Successfully recreated patching history table'
            ];
        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error recreating database structure: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

trait awxTrait {
    private $awx;
    
    public function initializeAWX() {
        $this->awx = new \awxPluginAnsible();
    }
}

class Patching extends phpef {
    use PatchingTrait, CMDBTrait, awxTrait;

    public function __construct() {
        parent::__construct();
        $this->initializePatchingConfig();
        $this->initializeCMDB();
        $this->initializeAWX();
    }

    //Protected function to define the settings for this plugin
    public function _pluginGetSettings() {
        // First verify we have the AWX connection details
        $awxConfig = $this->config->get("Plugins", "awx");
        if (!isset($awxConfig['Ansible-URL']) || empty($awxConfig['Ansible-URL'])) {
            $this->logging->writeLog("Patching", "AWX URL not configured", "error");
            return array(
                'Plugin Settings' => array(
                    $this->settingsOption('auth', 'ACL-ADMIN', ['label' => 'Patching Admin ACL']),
                ),
                'Error' => array(
                    $this->settingsOption('text', 'AWX-Error', [
                        'label' => 'Error',
                        'description' => 'Please configure AWX URL and Token in the AWX plugin settings first.',
                        'disabled' => true
                    ])
                )
            );
        }

        // Get available labels for selection
        $labels = $this->awx->GetAnsibleLabels() ?? [];
        $labelOptions = array_map(function($label) {
            return [
                'name' => $label['name'],
                'value' => $label['name']
            ];
        }, $labels);

        // Get the configured patching label and template patterns
        $patchingConfig = $this->config->get('Plugins', 'Patching') ?? [];
        $patchingLabel = is_array($patchingConfig['Patching-Label']) ? 
            $patchingConfig['Patching-Label'][0] : 
            ($patchingConfig['Patching-Label'] ?? 'Patching');
        $templatePatterns = is_array($patchingConfig['Template-Name-Patterns']) ? 
            implode(',', $patchingConfig['Template-Name-Patterns']) : 
            ($patchingConfig['Template-Name-Patterns'] ?? 'Windows, RHEL, Oracle');
        $patterns = array_map('trim', explode(',', $templatePatterns));

        // Get templates based on selected label
        $templates = [];
        if ($patchingLabel) {
            $this->logging->writeLog("Patching", "Fetching templates with label: " . $patchingLabel, "debug");
            $templates = $this->awx->GetAnsibleJobTemplate(null, $patchingLabel) ?? [];
        }
        
        // Create a single list of all template options
        $templateOptions = array_map(function($template) {
            return [
                'name' => $template['name'],
                'value' => $template['id']
            ];
        }, $templates);

        return array(
            'Plugin Settings' => array(
                $this->settingsOption('auth', 'ACL-ADMIN', ['label' => 'Patching Admin ACL']),
            ),
            'Configuration' => array(
                $this->settingsOption('select2', 'Patching-Label', [
                    'label' => 'Patching Label',
                    'description' => 'Label used to identify patching templates in AWX',
                    'options' => $labelOptions,
                    'value' => $patchingLabel,
                    'settings' => '{tags: true, tokenSeparators: [","], closeOnSelect: false, allowClear: true, width: "100%"}',
                    'placeholder' => 'Select or enter patching labels...'
                ]),
                $this->settingsOption('text', 'Template-Name-Patterns', [
                    'label' => 'Template Name Patterns',
                    'description' => 'Comma separated list of template name patterns',
                    'placeholder' => 'Enter template name patterns...'
                ])
            ),
            'Ansible Template Settings' => array_map(function($pattern) use ($templateOptions, $patchingConfig) {
                $configKey = $pattern . '-Patching-Template';
                $savedValue = isset($patchingConfig[$configKey]) && is_array($patchingConfig[$configKey]) ? 
                    $patchingConfig[$configKey][0] : 
                    ($patchingConfig[$configKey] ?? '');
                
                return $this->settingsOption('select2', $configKey, [
                    'label' => $pattern . ' Patching Template',
                    'description' => 'AWX Job Template for ' . $pattern . ' Server Patching',
                    'options' => $templateOptions,
                    'value' => $savedValue,
                    'settings' => '{tags: false, closeOnSelect: true, allowClear: true, width: "100%"}'
                ]);
            }, $patterns),
            'Cron Jobs' => array(
                $this->settingsOption('title', 'PatchingCronTitle', ['text' => 'Patching Cron Jobs']),
                $this->settingsOption('cron', 'Patching-Check-Schedule', [
                    'label' => 'Patching Check Schedule', 
                    'placeholder' => '0 * * * *',
                    'description' => 'How often to check for servers due for patching (default: every hour at minute 0)'
                ]),
                $this->settingsOption('cron', 'statusUpdateSchedule', [
                    'label' => 'Status Update Schedule', 
                    'placeholder' => '55 * * * *',
                    'description' => 'How often to check and update job statuses (default: every hour at minute 55)'
                ]),
                $this->settingsOption('test', '/api/plugin/Patching/cron/execute', [
                    'label' => 'Run Patching Check', 
                    'text' => 'Run Now', 
                    'Method' => 'GET'
                ]),
                $this->settingsOption('test', '/api/plugin/Patching/jobs/update-status', [
                    'label' => 'Update Job Statuses', 
                    'text' => 'Update Now', 
                    'Method' => 'GET'
                ])
            )
        );
    }

    /**
     * Private function to search for servers with patching configuration
     * @param array $filters Array of filters (name, os, day, hour, frequency)
     * @return array Results from the query
     */
    private function _searchServersSQL($filters = []) {
        // Build the base query
        $query = "SELECT name, Operating_System, Patching_Day, Patching_Hour, Patching_Frequency 
                 FROM cmdb WHERE 1=1";
        $params = [];

        // Add filters if provided
        if (!empty($filters['name'])) {
            $query .= " AND name LIKE :name";
            $params[':name'] = "%{$filters['name']}%";
        }
        if (!empty($filters['os'])) {
            $query .= " AND Operating_System = :os";
            $params[':os'] = $filters['os'];
        }
        if (!empty($filters['day'])) {
            $query .= " AND Patching_Day = :day";
            $params[':day'] = $filters['day'];
        }
        if (!empty($filters['hour'])) {
            $query .= " AND Patching_Hour = :hour";
            $params[':hour'] = $filters['hour'];
        }
        if (!empty($filters['frequency'])) {
            $query .= " AND Patching_Frequency = :frequency";
            $params[':frequency'] = $filters['frequency'];
        }

        // Execute the query
        $stmt = $this->sql->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Public function to search for servers with patching configuration
     * @param \Psr\Http\Message\ServerRequestInterface $request The request object
     * @return array Results from the query
     */
    public function searchServers($request) {
        // Get query parameters from the request
        $filters = [];
        $queryParams = $request->getQueryParams();
        
        // Map query parameters to filters
        $validFilters = ['name', 'os', 'day', 'hour', 'frequency'];
        foreach ($validFilters as $filter) {
            if (isset($queryParams[$filter])) {
                $filters[$filter] = $queryParams[$filter];
            }
        }
        
        return $this->_searchServersSQL($filters);
    }

    /**
     * Private function to get servers due for patching
     * @return array Results from the query
     */
    private function _getServersDuePatchingSQL() {
        $currentDay = strtolower(date('l')); // Get current day name
        $currentHour = (int)date('H'); // Get current hour in 24-hour format

        $query = "SELECT name, Operating_System, Patching_Day, Patching_Hour, Patching_Frequency 
                 FROM servers 
                 WHERE LOWER(Patching_Day) = :day 
                 AND Patching_Hour = :hour";

        $stmt = $this->sql->prepare($query);
        $stmt->execute([
            ':day' => $currentDay,
            ':hour' => $currentHour
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get servers that are due for patching based on current time
     * @return array Results from the query
     */
    public function getServersDueForPatching() {
        // Get current day name and hour
        $currentDay = date('l'); // Returns full day name (e.g., "Monday")
        $currentHour = date('H'); // 24-hour format

        // Add debug logging
        error_log("Checking for servers due for patching on $currentDay at hour $currentHour");

        // Build the query - using CAST to handle string/integer hour comparison
        $query = "SELECT name, Operating_System, Patching_Day, Patching_Hour, Patching_Frequency 
                 FROM cmdb 
                 WHERE LOWER(Patching_Day) = LOWER(:day) 
                 AND (
                     CAST(Patching_Hour AS INTEGER) = CAST(:hour AS INTEGER)
                     OR Patching_Hour = :hour_padded
                     OR Patching_Hour = :hour_unpadded
                 )";

        // Prepare different hour formats for matching
        $params = [
            ':day' => $currentDay,
            ':hour' => $currentHour,
            ':hour_padded' => sprintf('%02d', intval($currentHour)), // e.g., "09"
            ':hour_unpadded' => intval($currentHour)                 // e.g., 9
        ];

        // Execute the query
        $stmt = $this->sql->prepare($query);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Log the results for debugging
        error_log("Found " . count($results) . " servers due for patching");
        foreach ($results as $server) {
            error_log("Server due: {$server['name']} scheduled for {$server['Patching_Day']} at {$server['Patching_Hour']}");
        }

        return $results;
    }

    /**
     * Categorize servers due for patching into OS-specific lists
     * @return array Lists of servers categorized by OS type
     */
    public function categorizeServersDueForPatching() {
        $servers = $this->getServersDueForPatching();
        
        $categorized = [
            'windows' => [],
            'oracle' => [],
            'rhel' => []
        ];
        
        // Define OS patterns (will be matched case-insensitive)
        $patterns = [
            'windows' => ['windows', 'win'],
            'oracle' => ['oracle'],
            'rhel' => ['rhel', 'red hat']
        ];
        
        foreach ($servers as $server) {
            $os = strtolower($server['Operating_System']);
            $categorized_flag = false;
            
            // Check each OS category
            foreach ($patterns as $category => $matches) {
                foreach ($matches as $match) {
                    if (strpos($os, $match) !== false) {
                        $categorized[$category][] = $server['name'];
                        $categorized_flag = true;
                        error_log("Server {$server['name']} with OS {$server['Operating_System']} categorized as {$category}");
                        break 2;
                    }
                }
            }
            
            // Log if server wasn't categorized
            if (!$categorized_flag) {
                error_log("Warning: Server {$server['name']} with OS {$server['Operating_System']} could not be categorized");
            }
        }
        
        // Log final counts
        foreach ($categorized as $type => $list) {
            error_log("Found " . count($list) . " {$type} servers due for patching");
        }
        
        return $categorized;
    }

    /**
     * Get patching history from database
     * 
     * @return array Results from the query
     */
    public function getPatchingHistory() {
        $stmt = $this->sql->prepare("SELECT * FROM patching_history ORDER BY timestamp DESC");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Log a patching job to history
     * @param string $serverName Name of the server
     * @param string $os Operating system of the server
     * @param string $status Status of the patching job (successful/failed)
     * @return bool Success/Failure
     */
    public function logPatchingJob($serverName, $os, $status) {
        $stmt = $this->sql->prepare("INSERT INTO patching_history (server_name, os, status) VALUES (?, ?, ?)");
        return $stmt->execute([$serverName, $os, $status]);
    }

    /**
     * Match server OS to appropriate inventory and add server if not exists
     * @param string $serverName Name of the server
     * @param string $serverOS Operating system of the server
     * @return array Results containing match status and details
     */
    public function matchServerToInventory($serverName, $serverOS) {
        try {
            // Verify AWX is initialized
            if (!$this->awx) {
                $this->logging->writeLog('Patching', 'AWX not initialized, reinitializing...', 'debug');
                $this->initializeAWX();
            }

            $awxConfig = $this->config->get("Plugins","awx");
            $inventories = $this->awx->GetAnsibleInventories();
            
            if (!$inventories || !is_array($inventories)) {
                $this->logging->writeLog('Patching', 'No inventories found or invalid response format', 'error');
                return [
                    'exists' => false,
                    'error' => 'Failed to retrieve AWX inventories',
                    'debug_info' => [
                        'awx_url' => $awxConfig['Ansible-URL'] ?? 'Not set',
                        'server_name' => $serverName,
                        'server_os' => $serverOS,
                        'available_inventories' => []
                    ]
                ];
            }
            
            // Prepare debug information
            $debugInfo = [
                'awx_url' => $awxConfig['Ansible-URL'] ?? 'Not set',
                'server_name' => $serverName,
                'server_os' => $serverOS,
                'available_inventories' => []
            ];
            
            foreach ($inventories as $inv) {
                $debugInfo['available_inventories'][] = [
                    'id' => $inv['id'],
                    'name' => $inv['name'],
                    'total_hosts' => $inv['total_hosts']
                ];
            }
            
            // Clean up the OS string to get base OS type
            $baseOS = '';
            if (stripos($serverOS, 'Windows Server') !== false) {
                $baseOS = 'Windows Server';
            } elseif (stripos($serverOS, 'Red Hat') !== false || stripos($serverOS, 'RHEL') !== false) {
                $baseOS = 'RHEL';
            } elseif (stripos($serverOS, 'Ubuntu') !== false) {
                $baseOS = 'Ubuntu';
            } elseif (stripos($serverOS, 'Oracle') !== false || stripos($serverOS, 'OEL') !== false) {
                $baseOS = 'Oracle';
            } else {
                $baseOS = $serverOS;
            }
            
            foreach ($inventories as $inventory) {
                if (stripos($inventory['name'], $baseOS) !== false) {
                    $this->logging->writeLog('Patching', "Found matching inventory {$inventory['name']} for OS {$baseOS}", 'debug');
                    
                    // Get all hosts in inventory
                    $endpoint = "inventories/{$inventory['id']}/hosts/";
                    $this->logging->writeLog('Patching', "Querying AWX API endpoint: {$endpoint}", 'debug');
                    
                    $hosts = $this->awx->QueryAnsible('get', $endpoint);
                    
                    $this->logging->writeLog('Patching', "Raw AWX API Response: " . json_encode($hosts, JSON_PRETTY_PRINT), 'debug');
                    
                    // Check if host exists by iterating through results
                    $hostExists = false;
                    if (is_array($hosts)) {
                        foreach ($hosts as $host) {
                            if (isset($host['name']) && strcasecmp($host['name'], $serverName) === 0) {
                                $hostExists = true;
                                $this->logging->writeLog('Patching', "Found matching host: {$host['name']}", 'debug');
                                break;
                            }
                        }
                    }
                    
                    $this->logging->writeLog('Patching', "Host exists check result: " . ($hostExists ? 'true' : 'false'), 'debug');
                    
                    $response = [
                        'exists' => true,
                        'inventory_id' => $inventory['id'],
                        'inventory_name' => $inventory['name'],
                        'matched_os' => $baseOS,
                        'host_exists' => $hostExists,
                        'debug_info' => array_merge($debugInfo, [
                            'api_response' => $hosts,
                            'endpoint' => $endpoint
                        ])
                    ];
                    
                    // Add host to inventory if it doesn't exist
                    if (!$hostExists) {
                        $this->logging->writeLog('Patching', "Host not found, attempting to add to inventory", 'debug');
                        
                        $hostData = [
                            'name' => $serverName,
                            'description' => 'Added by Patching Plugin',
                            'enabled' => true,
                            'inventory' => $inventory['id']
                        ];
                        
                        $addResult = $this->awx->QueryAnsible('post', $endpoint, $hostData);
                        $this->logging->writeLog('Patching', "Add host result: " . json_encode($addResult, JSON_PRETTY_PRINT), 'debug');
                        
                        if (isset($addResult['id'])) {
                            $hostExists = true;  // Host was successfully added
                            $response['host_exists'] = true;
                        }
                        
                        $response['add_result'] = [
                            'success' => isset($addResult['id']),
                            'host_id' => $addResult['id'] ?? null,
                            'error' => isset($addResult['detail']) ? $addResult['detail'] : null
                        ];
                    }
                    
                    return $response;
                }
            }
            
            return [
                'exists' => false, 
                'error' => 'No matching inventory found for OS type: ' . $baseOS,
                'debug_info' => $debugInfo
            ];
        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error matching server to inventory: ' . $e->getMessage(), 'error');
            return [
                'exists' => false, 
                'error' => $e->getMessage(),
                'debug_info' => $debugInfo ?? ['error' => 'Debug info not available due to exception']
            ];
        }
    }

    /**
     * Check if a server exists in AWX inventory
     * @param string $serverName Name of the server to check
     * @return array Results containing existence status and details
     */
    public function checkServerInAWX($serverName) {
        // First get the server's OS from CMDB
        $this->logging->writeLog('Patching', 'Searching CMDB for server: ' . $serverName, 'debug');
        $serverDetails = $this->_searchServersSQL(['name' => $serverName]);
        
        if (!$serverDetails || empty($serverDetails)) {
            $this->logging->writeLog('Patching', 'Server not found in CMDB: ' . $serverName, 'error');
            return ['exists' => false, 'error' => 'Server not found in CMDB'];
        }

        $this->logging->writeLog('Patching', 'Server details from CMDB: ' . print_r($serverDetails, true), 'debug');
        
        // Check if OS field exists and is not empty
        if (!isset($serverDetails[0]['Operating_System']) || empty($serverDetails[0]['Operating_System'])) {
            $this->logging->writeLog('Patching', 'Operating System field missing or empty for server: ' . $serverName, 'error');
            return ['exists' => false, 'error' => 'Operating System information not found in CMDB'];
        }

        $serverOS = $serverDetails[0]['Operating_System'];
        $this->logging->writeLog('Patching', 'Found Operating System in CMDB: ' . $serverOS, 'debug');
        
        return $this->matchServerToInventory($serverName, $serverOS);
    }

    /**
     * Launch a job for a server, automatically determining the correct template based on OS
     * @param string $serverName Name of the server
     * @param string $serverOS Operating system of the server
     * @return array Results containing job launch status and details
     */
    public function launchServerJob($serverName, $serverOS = null) {
        try {
            // If OS not provided, try to get it from the database
            if (!$serverOS) {
                $serverDetails = $this->getServerDetails($serverName);
                if (!$serverDetails || empty($serverDetails['os'])) {
                    return [
                        'success' => false,
                        'error' => "Could not determine OS for server: {$serverName}"
                    ];
                }
                $serverOS = $serverDetails['os'];
            }

            // Get job template ID from config
            $patchingConfig = $this->config->get("Plugins", "Patching");
            
            // Determine OS type and corresponding template key
            $templateKey = '';
            if (stripos($serverOS, 'Windows Server') !== false) {
                $templateKey = 'Windows-Patching-Template';
            } elseif (stripos($serverOS, 'Red Hat') !== false || stripos($serverOS, 'RHEL') !== false) {
                $templateKey = 'RHEL-Patching-Template';
            } elseif (stripos($serverOS, 'Ubuntu') !== false) {
                $templateKey = 'Ubuntu-Patching-Template';
            } elseif (stripos($serverOS, 'Oracle') !== false || stripos($serverOS, 'OEL') !== false) {
                $templateKey = 'Oracle-Patching-Template';
            } else {
                return [
                    'success' => false,
                    'error' => "Unsupported OS type: {$serverOS}"
                ];
            }

            // Get template ID from config
            $jobTemplateId = is_array($patchingConfig[$templateKey]) ? 
                $patchingConfig[$templateKey][0] : 
                ($patchingConfig[$templateKey] ?? null);

            if (!$jobTemplateId) {
                return [
                    'success' => false,
                    'error' => "No job template configured for OS type: {$serverOS} (looking for {$templateKey})"
                ];
            }

            // First ensure server is in the correct inventory
            $inventoryCheck = $this->matchServerToInventory($serverName, $serverOS);
            if (!$inventoryCheck['exists'] || !($inventoryCheck['host_exists'] || ($inventoryCheck['add_result']['success'] ?? false))) {
                return [
                    'success' => false,
                    'error' => "Server must be in AWX inventory before launching jobs",
                    'inventory_check' => $inventoryCheck
                ];
            }

            // Launch the job with the correct template
            $result = $this->launchJobTemplate($serverName, $jobTemplateId);
            
            // Add OS info to the result
            $result['os_type'] = $serverOS;
            $result['template_key'] = $templateKey;
            
            return $result;

        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error launching server job: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Launch an AWX job template for a specific server
     * @param string $serverName Name of the server to run job for
     * @param int $jobTemplateId ID of the job template to launch
     * @return array Results containing job launch status and details
     */
    public function launchJobTemplate($serverName, $jobTemplateId) {
        try {
            // Verify AWX is initialized
            if (!$this->awx) {
                $this->logging->writeLog('Patching', 'AWX not initialized, reinitializing...', 'debug');
                $this->initializeAWX();
            }

            // Prepare the extra variables for the job
            $extraVars = [
                'name' => $serverName
            ];

            // Launch the job template
            $launchData = [
                'extra_vars' => json_encode($extraVars)
            ];

            $result = $this->awx->QueryAnsible('post', "job_templates/{$jobTemplateId}/launch/", $launchData);

            if (!$result) {
                $this->logging->writeLog('Patching', "Failed to launch job template {$jobTemplateId} for server {$serverName}", 'warning');
                return [
                    'success' => false,
                    'error' => 'Failed to launch job template - no response'
                ];
            }

            if (isset($result['error']) || isset($result['detail'])) {
                $error = $result['error'] ?? $result['detail'] ?? 'Unknown error';
                $this->logging->writeLog('Patching', "Error launching job template: {$error}", 'warning');
                return [
                    'success' => false,
                    'error' => $error
                ];
            }

            return [
                'success' => true,
                'job_id' => $result['id'] ?? null,
                'status' => $result['status'] ?? 'unknown',
                'job_template_id' => $jobTemplateId,
                'server_name' => $serverName
            ];

        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error launching job template: ' . $e->getMessage(), 'warning');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Execute cron job for this plugin
     * @param array $args Command line arguments
     * @return array Results of cron job execution
     */
    public function executeCronJob($args = []) {
        try {
            $this->logging->writeLog('Patching', 'Starting patching cron job...', 'info');
            
            // Step 1: Get servers due for patching
            $this->logging->writeLog('Patching', 'Getting servers due for patching...', 'info');
            $serversResponse = $this->getServersDueForPatching();
            
            // Handle both response formats (direct array or wrapped in result/data)
            $servers = is_array($serversResponse) ? $serversResponse : 
                      (isset($serversResponse['data']) ? $serversResponse['data'] : []);
            
            if (empty($servers)) {
                $this->logging->writeLog('Patching', 'No servers due for patching', 'info');
                return [
                    'success' => true,
                    'message' => 'No servers due for patching',
                    'data' => []
                ];
            }
            
            // Process each server
            $this->logging->writeLog('Patching', 'Found ' . count($servers) . ' servers due for patching', 'info');

            $results = [];
            foreach ($servers as $server) {
                $serverName = $server['name'];
                $serverOS = $server['Operating_System'];
                
                $this->logging->writeLog('Patching', "Processing server: {$serverName} ({$serverOS})", 'info');
                
                try {
                    // Step 2: Launch job for the server
                    $jobResult = $this->launchServerJob($serverName, $serverOS);
                    
                    if (!$jobResult['success']) {
                        $this->logging->writeLog('Patching', "Failed to launch job for {$serverName}: " . ($jobResult['error'] ?? 'Unknown error'), 'warning');
                    } else {
                        $this->logging->writeLog('Patching', "Successfully launched job for {$serverName}", 'info');
                    }
                    
                    // Add to results
                    $results[] = [
                        'server_name' => $serverName,
                        'os_type' => $serverOS,
                        'patching_schedule' => [
                            'day' => $server['Patching_Day'],
                            'hour' => $server['Patching_Hour'],
                            'frequency' => $server['Patching_Frequency']
                        ],
                        'job_launch_result' => $jobResult
                    ];
                    
                } catch (Exception $e) {
                    $this->logging->writeLog('Patching', "Error processing server {$serverName}: " . $e->getMessage(), 'warning');
                    $results[] = [
                        'server_name' => $serverName,
                        'os_type' => $serverOS,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Record successful jobs in patching history
            foreach ($results as $result) {
                if (isset($result['job_launch_result']) && $result['job_launch_result']['success']) {
                    $this->recordPatchingHistory($result['server_name'], [
                        'success' => true,
                        'job_id' => $result['job_launch_result']['job_id'],
                        'job_template_id' => $result['job_launch_result']['job_template_id'],
                        'template_key' => $result['job_launch_result']['template_key'] ?? 'unknown',
                        'status' => 'Launched',
                        'os_type' => $result['os_type']
                    ]);
                }
            }
            
            $response = [
                'success' => true,
                'message' => count($results) > 0 ? 'Successfully processed servers' : 'No servers processed',
                'data' => $results
            ];
            
            $this->logging->writeLog('Patching', 'Cron job completed with response', 'info', $response);
            return $response;
            
        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error executing cron job: ' . $e->getMessage(), 'warning');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function recordPatchingHistory($serverName, $jobResult) {
        try {
            // Debug log the input
            $this->logging->writeLog('Patching', 'Recording patching history with data: ' . print_r(['server' => $serverName, 'job' => $jobResult], true), 'debug');

            if (!isset($jobResult['success']) || !$jobResult['success']) {
                $this->logging->writeLog('Patching', 'Not recording unsuccessful job', 'debug');
                return false;
            }

            // Verify required fields
            $requiredFields = ['job_id', 'job_template_id', 'template_key', 'status', 'os_type'];
            foreach ($requiredFields as $field) {
                if (!isset($jobResult[$field])) {
                    $this->logging->writeLog('Patching', "Missing required field: {$field} in job result", 'error');
                    return false;
                }
            }

            // Get AWX URL from config
            $awxConfig = $this->config->get("Plugins", "awx");
            $awxUrl = $awxConfig['Ansible-URL'] ?? '';
            if (empty($awxUrl)) {
                $this->logging->writeLog('Patching', 'AWX URL not configured', 'error');
                return false;
            }

            // Remove trailing slash if present
            $awxUrl = rtrim($awxUrl, '/');
            $jobLink = "{$awxUrl}/#/jobs/playbook/{$jobResult['job_id']}/output";

            // Ensure SQL connection is initialized and valid
            if (!$this->sql) {
                $this->logging->writeLog('Patching', 'SQL connection not initialized, initializing...', 'debug');
                $this->initializeCMDB();
            }

            try {
                // Test the connection
                $this->sql->query("SELECT 1");
            } catch (PDOException $e) {
                $this->logging->writeLog('Patching', 'SQL connection lost, reinitializing...', 'debug');
                $this->initializeCMDB();
            }
            
            // Insert the record
            $sql = "INSERT INTO patching_history 
                    (server_name, job_id, job_link, template_id, template_key, initial_status, os_type) 
                    VALUES 
                    (:server_name, :job_id, :job_link, :template_id, :template_key, :initial_status, :os_type)";

            $stmt = $this->sql->prepare($sql);
            $params = [
                ':server_name' => $serverName,
                ':job_id' => $jobResult['job_id'],
                ':job_link' => $jobLink,
                ':template_id' => $jobResult['job_template_id'],
                ':template_key' => $jobResult['template_key'],
                ':initial_status' => $jobResult['status'],
                ':os_type' => $jobResult['os_type']
            ];

            // Debug log the SQL and parameters
            $this->logging->writeLog('Patching', 'Executing SQL with params: ' . print_r($params, true), 'debug');

            $result = $stmt->execute($params);

            if ($result) {
                $this->logging->writeLog('Patching', "Successfully recorded patching history for server {$serverName} with job ID {$jobResult['job_id']}", 'info');
                return true;
            } else {
                $error = $stmt->errorInfo();
                $this->logging->writeLog('Patching', "Failed to record patching history: " . ($error[2] ?? 'Unknown error'), 'warning');
                return false;
            }
        } catch (Exception $e) {
            $this->logging->writeLog('Patching', "Error recording patching history: " . $e->getMessage(), 'warning');
            return false;
        }
    }

    /**
     * Update the status of pending patching jobs
     * @return array Results of the update operation
     */
    public function updatePatchingJobStatuses() {
        try {
            // Ensure SQL connection is initialized and valid
            if (!$this->sql) {
                $this->logging->writeLog('Patching', 'SQL connection not initialized, initializing...', 'debug');
                $this->initializeCMDB();
            }

            // Get all jobs that don't have a final status
            $stmt = $this->sql->prepare("
                SELECT * FROM patching_history 
                WHERE final_status IS NULL 
                OR final_status IN ('Pending', 'Running')
            ");
            $stmt->execute();
            $pendingJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($pendingJobs)) {
                return [
                    'success' => true,
                    'message' => 'No pending jobs to update',
                    'updated_count' => 0
                ];
            }

            $this->logging->writeLog('Patching', 'Found ' . count($pendingJobs) . ' jobs to update', 'info');
            
            // Initialize AWX connection if needed
            if (!$this->awx) {
                $this->initializeAWX();
            }

            $updatedCount = 0;
            foreach ($pendingJobs as $job) {
                try {
                    // Get job status from AWX
                    $jobDetails = $this->awx->GetAnsibleJobs($job['job_id']);
                    
                    if (!$jobDetails) {
                        $this->logging->writeLog('Patching', "Could not get status for job {$job['job_id']}", 'warning');
                        continue;
                    }

                    // Map AWX status to our status
                    $status = $jobDetails['status'] ?? 'unknown';
                    $finalStatus = $this->mapAWXStatus($status);
                    
                    // Only update if status has changed
                    if ($finalStatus !== $job['final_status']) {
                        // Update job in database
                        $updateStmt = $this->sql->prepare("
                            UPDATE patching_history 
                            SET final_status = :status,
                                last_checked = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        
                        $updateResult = $updateStmt->execute([
                            ':status' => $finalStatus,
                            ':id' => $job['id']
                        ]);

                        if ($updateResult) {
                            $this->logging->writeLog('Patching', "Updated status for job {$job['job_id']} from {$job['final_status']} to {$finalStatus}", 'info');
                            $updatedCount++;
                        } else {
                            $error = $updateStmt->errorInfo();
                            $this->logging->writeLog('Patching', "Failed to update job {$job['job_id']}: " . ($error[2] ?? 'Unknown error'), 'warning');
                        }
                    } else {
                        // Just update the last_checked timestamp
                        $updateStmt = $this->sql->prepare("
                            UPDATE patching_history 
                            SET last_checked = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $updateStmt->execute([':id' => $job['id']]);
                    }
                } catch (Exception $e) {
                    $this->logging->writeLog('Patching', "Error updating job {$job['job_id']}: " . $e->getMessage(), 'warning');
                    continue;
                }
            }

            return [
                'success' => true,
                'message' => "Successfully updated {$updatedCount} job(s)",
                'updated_count' => $updatedCount
            ];
        } catch (Exception $e) {
            $this->logging->writeLog('Patching', 'Error updating job statuses: ' . $e->getMessage(), 'error');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Map AWX job status to our status format
     * @param string $awxStatus The status from AWX
     * @return string Our status format
     */
    private function mapAWXStatus($awxStatus) {
        $statusMap = [
            'successful' => 'Successful',
            'failed' => 'Failed',
            'error' => 'Error',
            'running' => 'Running',
            'pending' => 'Pending',
            'waiting' => 'Pending',
            'canceled' => 'Cancelled'
        ];

        return $statusMap[strtolower($awxStatus)] ?? 'Unknown';
    }
}
