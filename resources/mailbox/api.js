import axios from 'axios';

const api = axios.create({
    baseURL: '/api/mailbox',
    headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
    const token = localStorage.getItem('mailbox_token');
    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
});

api.interceptors.response.use(
    (r) => r,
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem('mailbox_token');
            if (location.pathname !== '/login') {
                location.assign('/login');
            }
        }
        return Promise.reject(error);
    },
);

export default api;
