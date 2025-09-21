<?php
require('header.php');
require('conn.php');

// Start session if not already started. This is crucial for $_SESSION
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check user type and redirect if not a student
if (!isset($_SESSION['usertype']) || $_SESSION['usertype'] != 'STUDENT') {
    session_destroy();
    header("location: login.php");
    exit();
}

?>
<style>
    /* Styling for the video and image preview elements */
    #preview {
        width: 100%; /* Make video responsive within its parent */
        max-width: 600px; /* Limit max width */
        height: auto; /* Maintain aspect ratio */
        border: solid 1px blueviolet;
    }

    #imagePreview {
        max-width: 100%;
        max-height: 400px; /* Limit height of image preview */
        display: none; /* Hidden by default when no image is selected */
        border: solid 1px blueviolet;
        object-fit: contain; /* Ensure the image fits within the bounds */
    }

    /* Utility class for hiding elements */
    .hidden {
        display: none !important;
    }

    /* Responsive adjustments for video and image preview based on screen width */
    @media only screen and (max-width: 600px) {
        #preview, #imagePreview {
            width: 300px;
            height: 300px; /* Enforce a square aspect ratio for smaller screens */
            object-fit: cover; /* Fill the element, potentially cropping, for video */
            object-fit: contain; /* Ensure image is fully visible for image preview */
        }
    }

    @media only screen and (max-width: 900px) {
        #preview, #imagePreview {
            width: 400px;
            height: 300px; /* Specific aspect ratio for medium screens */
            object-fit: cover;
            object-fit: contain;
        }
    }
</style>

<div class="container pt-3 px-4 m-0">
    <nav style="--bs-breadcrumb-divider: url(&#34;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8'%3E%3Cpath d='M2.5 0L1 1.5 3.5 4 1 6.5 2.5 8l4-4-4-4z' fill='%236c757d'/%3E%3C/svg%3E&#34;);" aria-label="breadcrumb">
        <ol class="breadcrumb mb-0 p-1 rounded-4" style="background: #eee;">
            <li class="breadcrumb-item">Home</li>
            <li class="breadcrumb-item">Attendance</li>
            <li class="breadcrumb-item">Mark Attendance</li>
        </ol>
    </nav>
</div>


<!-- Main Content Start -->
<div class="container-fluid pt-4 px-4">
    <div class="text-center w-100">
        <?php
        // Display session messages if any
        if (isset($_SESSION['msg'])) {
            echo $_SESSION['msg'];
            unset($_SESSION['msg']);
        }
        ?>
    </div>

    <div class="row bg-light rounded mx-0">
        <div class="col-12">
            <div class="bg-light rounded h-100 p-4">
                <h6 class="mb-1">Mark Attendance</h6>
                <p class="mb-4 text-primary">*Note: Scan QR Code or Upload Image to Mark Attendance.</p>

                <!-- Alerts for location, mode switching, and scanner status -->
                <div class="alert alert-danger fw-bold" id="locationWarnAlert" role="alert">
                    Please Allow Location Permission To Give Attendance.
                </div>
                <div class="alert alert-info fw-bold hidden" id="modeSwitchAlert" role="alert">
                </div>

                <!-- Mode Switcher Buttons -->
                <div class="d-flex justify-content-center mb-3">
                    <button class="btn btn-primary me-2" id="cameraModeBtn">Use Camera</button>
                    <button class="btn btn-secondary" id="uploadModeBtn">Upload QR Image</button>
                </div>

                <!-- Camera/Scanner Section (Initially visible) -->
                <div id="cameraSection" class="text-center">
                    <div class="alert alert-warning fw-bold hidden" id="cameraStatusAlert" role="alert">
                        Initializing Camera...
                    </div>
                    <video id="preview" class="img-thumbnail"></video>
                    <div class="btn-group btn-group-toggle mb-5 text-center w-100" data-toggle="buttons">
                        <label class="btn btn-primary active">
                            <input type="radio" name="options" value="1" autocomplete="off" checked> Front Camera
                        </label>
                        <label class="btn btn-secondary">
                            <input type="radio" name="options" value="2" autocomplete="off"> Back Camera
                        </label>
                    </div>
                </div>

                <!-- File Upload Section (Initially hidden) -->
                <div id="uploadSection" class="text-center hidden">
                    <p class="mb-3">Upload an image containing the QR Code.</p>
                    <input type="file" id="qrFileInput" accept="image/*" class="form-control mb-3">
                    <img id="imagePreview" alt="QR Code Preview" class="img-thumbnail">
                    <canvas id="qrCanvas" class="hidden"></canvas> <!-- Hidden canvas for image processing with jsQR -->
                    <button class="btn btn-primary mt-3" id="scanImageBtn" disabled>Scan QR from Image</button>
                    <div class="alert alert-info fw-bold mt-3 hidden" id="scanImageStatus" role="alert"></div>
                </div>

            </div>
        </div>
    </div>
</div>
<!-- Main Content End -->

<!-- Required JavaScript Libraries -->
<script src="https://rawgit.com/schmich/instascan-builds/master/instascan.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.1.0/dist/jsQR.min.js"></script>

<script>
    // Global variables to store location and IP
    let lat, lon, clientIp;
    // Variables for Instascan (camera scanner)
    let scanner = null;
    let selectedCamera = null;
    let camerasAvailable = [];

    // References to DOM elements for easier access
    const locationWarnAlert = document.getElementById("locationWarnAlert");
    const cameraSection = document.getElementById("cameraSection");
    const uploadSection = document.getElementById("uploadSection");
    const cameraModeBtn = document.getElementById("cameraModeBtn");
    const uploadModeBtn = document.getElementById("uploadModeBtn");
    const qrFileInput = document.getElementById("qrFileInput");
    const imagePreview = document.getElementById("imagePreview");
    const qrCanvas = document.getElementById("qrCanvas"); // Hidden canvas for image scanning
    const scanImageBtn = document.getElementById("scanImageBtn");
    const scanImageStatus = document.getElementById("scanImageStatus");
    const videoPreview = document.getElementById('preview');
    const cameraStatusAlert = document.getElementById('cameraStatusAlert');
    const modeSwitchAlert = document.getElementById('modeSwitchAlert');

    /**
     * Displays an alert message.
     * @param {HTMLElement} element - The DOM element to display the message in.
     * @param {string} message - The message text.
     * @param {string} type - Bootstrap alert type (e.g., 'info', 'success', 'warning', 'danger').
     * @param {number} [hideAfter=0] - Duration in milliseconds after which the alert should be hidden (0 for no auto-hide).
     */
    function showAlert(element, message, type = 'info', hideAfter = 0) {
        element.className = `alert alert-${type} fw-bold`; // Update class for styling
        element.innerHTML = message;
        element.classList.remove('hidden'); // Make sure it's visible
        if (hideAfter > 0) {
            setTimeout(() => element.classList.add('hidden'), hideAfter);
        }
    }

    // --- Location Handling ---
    /**
     * Attempts to get the user's current geographic location.
     * Displays status messages and handles potential errors.
     */
    function getLocation() {
        if (navigator.geolocation) {
            showAlert(locationWarnAlert, "Requesting location permission...", 'info');
            navigator.geolocation.getCurrentPosition(showPosition, showError, {
                enableHighAccuracy: true, // Request more accurate results
                timeout: 10000,           // Maximum time allowed to retrieve location
                maximumAge: 0             // Force retrieve new location every time
            });
        } else {
            // Geolocation is not supported by the browser
            showAlert(locationWarnAlert, "Geolocation is not supported by this browser. Cannot proceed.", 'danger');
            // Disable all attendance-related functionality if location is crucial
            cameraModeBtn.disabled = true;
            uploadModeBtn.disabled = true;
            qrFileInput.disabled = true;
            scanImageBtn.disabled = true;
        }
    }

    /**
     * Callback function for successful geolocation.
     * Stores latitude and longitude, then fetches client IP.
     * @param {GeolocationPosition} position - The position object.
     */
    function showPosition(position) {
        lat = position.coords.latitude;
        lon = position.coords.longitude;
        showAlert(locationWarnAlert, `Location obtained: Lat ${lat.toFixed(4)}, Lon ${lon.toFixed(4)}.`, 'success', 3000);
        locationWarnAlert.classList.add('hidden'); // Hide the alert after success animation

        // Once location is confirmed, fetch client IP
        fetchClientIp();
    }

    /**
     * Callback function for geolocation errors.
     * Displays specific error messages to the user and disables functionality.
     * @param {GeolocationPositionError} error - The error object.
     */
    function showError(error) {
        let message = "An unknown error occurred.";
        switch (error.code) {
            case error.PERMISSION_DENIED:
                message = "Location permission denied. Please allow location access to mark attendance.";
                break;
            case error.POSITION_UNAVAILABLE:
                message = "Location information is unavailable. Please check your device's location settings.";
                break;
            case error.TIMEOUT:
                message = "The request to get user location timed out. Please try again.";
                break;
            case error.UNKNOWN_ERROR:
                message = "An unknown error occurred while trying to get location.";
                break;
        }
        showAlert(locationWarnAlert, message, 'danger');
        // If location is denied or unavailable, disable interaction
        cameraModeBtn.disabled = true;
        uploadModeBtn.disabled = true;
        qrFileInput.disabled = true;
        scanImageBtn.disabled = true;
    }

    /**
     * Fetches the client's public IP address from a third-party service.
     */
    function fetchClientIp() {
        $.get('https://api.ipify.org?format=json')
            .done(function(data) {
                clientIp = data.ip;
                // Location and IP are now ready, proceed with initializing attendance methods
                initializeAttendanceMethods();
            })
            .fail(function() {
                clientIp = 'N/A'; // Fallback if IP fetch fails
                showAlert(locationWarnAlert, "Could not fetch client IP address. Proceeding without IP. (Attendance might rely on IP for verification)", 'warning', 8000);
                initializeAttendanceMethods(); // Still initialize, as IP isn't always strictly mandatory
            });
    }

    // --- QR Code Processing (Unified) ---
    /**
     * Processes the scanned QR code content along with location and IP.
     * Redirects to the API endpoint to record attendance.
     * @param {string} content - The data extracted from the QR code.
     */
    function processQRCode(content) {
        if (lat && lon) {
            // Encode content to handle special characters in URL
            const encodedContent = encodeURIComponent(content);
            const redirectUrl = `api_give_attend.php?data=${encodedContent}&lat=${lat}&lon=${lon}&ip_address=${clientIp}`;
            window.location.href = redirectUrl;
        } else {
            showAlert(locationWarnAlert, "Location not available. Cannot process attendance. Please allow location access.", 'danger', 5000);
        }
    }

    // --- Camera Mode Functions ---
    /**
     * Initializes and starts the camera QR scanner (Instascan).
     */
    function initializeCameraScanner() {
        if (scanner) {
            scanner.stop(); // Stop any existing scanner instance before re-initializing
            scanner = null;
        }
        showAlert(cameraStatusAlert, "Initializing camera...", 'info');

        scanner = new Instascan.Scanner({
            video: videoPreview,
            scanPeriod: 5,  // Scan every 5 milliseconds
            mirror: false   // Set to false for original image, true for mirrored (selfie mode)
        });

        // Event listener for a successful QR code scan
        scanner.addListener('scan', function(content) {
            showAlert(cameraStatusAlert, `QR Code Detected: ${content.substring(0, 30)}... Processing...`, 'success');
            scanner.stop(); // Stop scanning immediately after a successful scan
            processQRCode(content);
        });

        // Get available cameras and start scanning
        Instascan.Camera.getCameras().then(function(cameras) {
            camerasAvailable = cameras; // Store available cameras for switching logic
            if (cameras.length > 0) {
                // Try to start with the back camera (index > 0 or 1) if available, otherwise front (index 0)
                // A more robust check might involve camera.name or camera.label
                selectedCamera = cameras[1] || cameras[0]; // Prefer back camera if available

                if (selectedCamera) {
                    scanner.start(selectedCamera).then(() => {
                        showAlert(cameraStatusAlert, "Camera ready. Point to a QR code.", 'success', 3000);
                        cameraStatusAlert.classList.add('hidden'); // Hide after successful start
                    }).catch(e => {
                        showAlert(cameraStatusAlert, `Error starting camera: ${e.message}. Please check browser permissions and try again.`, 'danger');
                        console.error('Error starting camera:', e);
                        // If camera fails, suggest switching to upload mode
                        cameraModeBtn.disabled = true;
                        showUploadMode();
                    });
                } else {
                    showAlert(cameraStatusAlert, "No suitable camera found.", 'danger');
                    console.error('No suitable camera found.');
                }

                // Camera switch logic for front/back buttons
                $('[name="options"]').off('change').on('change', function() { // Use .off().on() to prevent multiple handlers
                    if (scanner) scanner.stop(); // Stop current stream before switching
                    const option = $(this).val();
                    let newCamera = null;

                    if (option == 1 && camerasAvailable[0]) { // Front Camera (usually index 0)
                        newCamera = camerasAvailable[0];
                    } else if (option == 2 && camerasAvailable[1]) { // Back Camera (often index 1, but depends on system)
                        newCamera = camerasAvailable[1];
                    }

                    if (newCamera) {
                        selectedCamera = newCamera;
                        scanner.start(selectedCamera).then(() => {
                            showAlert(cameraStatusAlert, `Switched to ${option == 1 ? 'Front' : 'Back'} Camera.`, 'info', 3000);
                        }).catch(e => {
                            showAlert(cameraStatusAlert, `Error switching camera: ${e.message}.`, 'danger');
                            console.error('Error switching camera:', e);
                        });
                    } else {
                        showAlert(cameraStatusAlert, `Selected camera not found! Attempting to find another camera.`, 'warning', 3000);
                        // Fallback to trying the other camera if the selected one isn't found
                        newCamera = (option == 1 && camerasAvailable[1]) ? camerasAvailable[1] : (option == 2 && camerasAvailable[0]) ? camerasAvailable[0] : null;
                        if (newCamera) {
                            selectedCamera = newCamera;
                            scanner.start(selectedCamera).then(() => {
                                showAlert(cameraStatusAlert, `Switched to fallback camera.`, 'info', 3000);
                            }).catch(e => {
                                showAlert(cameraStatusAlert, `Error starting fallback camera: ${e.message}.`, 'danger');
                                console.error('Error starting fallback camera:', e);
                            });
                        } else {
                            showAlert(cameraStatusAlert, "No alternate camera available.", 'danger', 3000);
                        }
                    }
                });
            } else {
                // No cameras found at all.
                showAlert(cameraStatusAlert, "No camera devices found. Please use the 'Upload QR Image' option.", 'warning');
                console.error('No cameras found.');
                // Disable camera mode button and automatically switch to upload mode
                cameraModeBtn.disabled = true;
                showUploadMode();
            }
        }).catch(function(e) {
            // General error accessing cameras
            showAlert(cameraStatusAlert, `Camera access error: ${e.message}. Please ensure camera permissions are granted.`, 'danger');
            console.error('Camera access error:', e);
            // Also suggest upload mode if camera access fails
            cameraModeBtn.disabled = true;
            showUploadMode();
        });
    }

    // --- Upload Mode Functions ---
    /**
     * Handles the file input change event. Displays the selected image preview.
     * @param {Event} event - The change event from the file input.
     */
    function handleImageUpload(event) {
        const file = event.target.files[0];
        if (!file) {
            imagePreview.style.display = 'none'; // Hide if no file selected
            scanImageBtn.disabled = true;
            showAlert(scanImageStatus, "No image selected.", 'info'); // Clear status
            return;
        }

        if (!file.type.startsWith('image/')) {
            showAlert(scanImageStatus, "Please select an image file (e.g., JPEG, PNG, GIF).", 'danger');
            imagePreview.style.display = 'none';
            scanImageBtn.disabled = true;
            return;
        }

        showAlert(scanImageStatus, "Image loaded. Click 'Scan QR from Image' to process.", 'info');
        scanImageBtn.disabled = false; // Enable scan button

        const reader = new FileReader(); // FileReader to read file content
        reader.onload = function(e) {
            imagePreview.src = e.target.result; // Set image source to display preview
            imagePreview.style.display = 'block'; // Make preview visible
            // No need for imagePreview.onload here, as jsQR will use the image's src directly when scan is triggered
        };
        reader.readAsDataURL(file); // Read file as Data URL
    }

    /**
     * Scans the currently displayed image for a QR code using jsQR.
     */
    function scanImageForQRCode() {
        if (!imagePreview.src || imagePreview.style.display === 'none') {
            showAlert(scanImageStatus, "Please select an image first to scan.", 'warning');
            return;
        }

        showAlert(scanImageStatus, "Scanning image for QR Code...", 'info');
        scanImageBtn.disabled = true; // Disable button while scanning to prevent multiple clicks

        const context = qrCanvas.getContext('2d');
        const img = new Image();
        img.onload = function() {
            // Set canvas dimensions to match the image
            qrCanvas.width = img.width;
            qrCanvas.height = img.height;
            // Draw the image onto the canvas
            context.drawImage(img, 0, 0, img.width, img.height);
            // Get image data from canvas for jsQR
            const imageData = context.getImageData(0, 0, qrCanvas.width, qrCanvas.height);

            // Use jsQR to find a QR code
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: "dontInvert", // Or "original" to scan the image as is, or "both"
            });

            if (code) {
                showAlert(scanImageStatus, `QR Code found: ${code.data.substring(0, 30)}... Processing...`, 'success');
                scanImageBtn.disabled = true; // Keep disabled after successful scan
                processQRCode(code.data);
            } else {
                showAlert(scanImageStatus, "No QR Code found in the image. Please try another image or ensure it's clear.", 'danger');
                scanImageBtn.disabled = false; // Re-enable for another attempt
            }
        };
        img.onerror = function() {
            showAlert(scanImageStatus, "Error loading image for scanning. It might be corrupted or an invalid format.", 'danger');
            scanImageBtn.disabled = false;
        };
        img.src = imagePreview.src; // Set image source from the preview
    }

    // --- Mode Switching Logic ---
    /**
     * Switches the UI to Camera Mode and initializes the camera scanner.
     */
    function showCameraMode() {
        if (lat && lon) { // Only allow camera if location is successfully obtained
            showAlert(modeSwitchAlert, "Switched to Camera Mode.", 'info', 3000);
            cameraSection.classList.remove('hidden'); // Show camera controls and video
            uploadSection.classList.add('hidden');    // Hide upload controls
            // Update button styles
            cameraModeBtn.classList.add('btn-primary');
            cameraModeBtn.classList.remove('btn-secondary');
            uploadModeBtn.classList.remove('btn-primary');
            uploadModeBtn.classList.add('btn-secondary');

            initializeCameraScanner(); // Start the camera feed
            // Clear any previous state from upload mode
            scanImageStatus.classList.add('hidden');
            imagePreview.style.display = 'none';
            qrFileInput.value = '';
            scanImageBtn.disabled = true;
        } else {
            // If location is not available, cannot switch to camera mode
            showAlert(modeSwitchAlert, "Cannot switch to camera mode. Location required first.", 'warning', 5000);
            showUploadMode(); // Force back to upload mode or keep current
        }
    }

    /**
     * Switches the UI to Upload QR Image Mode and stops any active camera.
     */
    function showUploadMode() {
        showAlert(modeSwitchAlert, "Switched to Upload QR Image Mode.", 'info', 3000);
        if (scanner) {
            scanner.stop(); // Stop camera scan if active
            scanner = null; // Clear scanner instance
        }
        cameraSection.classList.add('hidden');    // Hide camera controls
        uploadSection.classList.remove('hidden'); // Show upload controls
        // Update button styles
        cameraModeBtn.classList.remove('btn-primary');
        cameraModeBtn.classList.add('btn-secondary');
        uploadModeBtn.classList.add('btn-primary');
        uploadModeBtn.classList.remove('btn-secondary');

        cameraStatusAlert.classList.add('hidden'); // Hide camera status messages
    }

    /**
     * Initializes the attendance methods (camera or upload) once location and IP are ready.
     */
    function initializeAttendanceMethods() {
        // By default, try to show camera mode. If it fails (no camera, permissions),
        // it will gracefully switch to upload mode within initializeCameraScanner().
        showCameraMode();
    }

    // --- Event Listeners and Initial Page Load ---
    document.addEventListener('DOMContentLoaded', function() {
        // --- IMPORTANT: Request location coordinates first ---
        // All attendance functionality depends on successfully getting location and IP.
        getLocation();

        // Event listeners for mode switching buttons
        cameraModeBtn.addEventListener('click', showCameraMode);
        uploadModeBtn.addEventListener('click', showUploadMode);

        // Event listeners specifically for the upload mode
        qrFileInput.addEventListener('change', handleImageUpload); // When a file is selected
        scanImageBtn.addEventListener('click', scanImageForQRCode); // When 'Scan' button is clicked
    });
</script>
<?php
require('footer.php');
?>