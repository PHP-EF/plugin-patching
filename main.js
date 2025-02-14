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
        toolbar: '#toolbar',
        refreshOptions: {
            silent: true
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
            formatter: timestampFormatter
        }, {
            field: "status",
            title: "Status",
            sortable: true,
            formatter: statusFormatter
        }, {
            field: "job_link",
            title: "Job Link",
            sortable: false,
            formatter: jobLinkFormatter
        }]
    });
}

// Timestamp formatter
function timestampFormatter(value) {
    return moment(value).format("YYYY-MM-DD HH:mm:ss");
}

// Status formatter
function statusFormatter(value) {
    value = value || 'Pending';
    if (value.toLowerCase() === "successful") {
        return "<span class='badge bg-success'>Successful</span>";
    } else if (value.toLowerCase() === "failed" || value.toLowerCase() === "error") {
        return "<span class='badge bg-danger'>" + value + "</span>";
    } else if (value.toLowerCase() === "pending") {
        return "<span class='badge bg-warning'>" + value + "</span>";
    } else {
        return "<span class='badge bg-secondary'>" + value + "</span>";
    }
}

// Job link formatter
function jobLinkFormatter(value) {
    return '<a href="' + value + '" target="_blank" class="btn btn-sm btn-primary">View Job</a>';
}