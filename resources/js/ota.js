const baseUrl = '/_native/api/call';

async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });
    const result = await response.json();
    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }
    return result.data?.data ?? result.data;
}

export const ota = {
    check: (params = {}) => bridgeCall('Ota.Check', params),
    download: (params = {}) => bridgeCall('Ota.Download', params),
    apply: (params = {}) => bridgeCall('Ota.Apply', params),
    rollback: () => bridgeCall('Ota.Rollback'),
    getStatus: () => bridgeCall('Ota.GetStatus'),
    prompt: (params = {}) => bridgeCall('Ota.Prompt', params),
};

export default ota;
