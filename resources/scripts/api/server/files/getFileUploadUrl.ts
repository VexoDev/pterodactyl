import http from '@/api/http';

export default (uuid: string, directory: string): Promise<string> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${uuid}/files/upload`, { params: { directory } })
            .then(({ data }) => resolve(data.attributes.url))
            .catch(reject);
    });
};
