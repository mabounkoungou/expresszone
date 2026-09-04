@extends('setup.main')

@section('content')
<div class="row mt-5">
    <div class="col-12 text-center">
        <h2>Installing application</h2>
        <p id="setup-status">Please keep this page open while the database is prepared.</p>
        <a id="setup-finished" class="btn btn-success d-none" href="{{ url('/setup/finish') }}">Continue</a>
        <a id="setup-retry" class="btn btn-danger d-none" href="{{ url('/setup/step-3') }}">Back to setup</a>
    </div>
</div>

<script>
(function () {
    const status = document.getElementById('setup-status');
    const finished = document.getElementById('setup-finished');
    const retry = document.getElementById('setup-retry');

    function checkStatus() {
        fetch('{{ url('/setup/status') }}', {headers: {'Accept': 'application/json'}})
            .then(response => response.json())
            .then(data => {
                status.textContent = data.message || 'Installation is running.';
                if (data.status === 'complete') {
                    finished.classList.remove('d-none');
                    return;
                }
                if (data.status === 'failed') {
                    retry.classList.remove('d-none');
                    return;
                }
                window.setTimeout(checkStatus, 2000);
            })
            .catch(() => window.setTimeout(checkStatus, 3000));
    }

    checkStatus();
}());
</script>
@endsection
