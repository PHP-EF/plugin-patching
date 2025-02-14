// Initialize patching history table when the page loads
$(document).ready(function() {
    if ($("#patchingHistoryTable").length) {
        initializePatchingHistoryTable();
    }
});

// Function to initialize the patching history table
function initializePatchingHistoryTable() {
    $("#patchingHistoryTable").bootstrapTable({
        url: "/api/plugin/Patching/history",
        method: 'get',
        pagination: true,
        search: true,
        showRefresh: true,
        pageSize: 25,
        refresh: {
            url: "/api/plugin/Patching/history",
            method: 'get'
        },
        columns: [{
            field: "server_name",
            title: "Server Name",
            sortable: true
        }, {
            field: "os",
            title: "Operating System",
            sortable: true
        }, {
            field: "timestamp",
            title: "Date/Time",
            sortable: true,
            formatter: function(value) {
                return moment(value).format("YYYY-MM-DD HH:mm:ss");
            }
        }, {
            field: "status",
            title: "Status",
            sortable: true,
            formatter: function(value) {
                if (value === "successful") {
                    return "<span class='badge bg-success'>Successful</span>";
                } else if (value === "failed") {
                    return "<span class='badge bg-danger'>Failed</span>";
                } else {
                    return "<span class='badge bg-warning'>" + value + "</span>";
                }
            }
        }]
    });
}