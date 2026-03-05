import http from '@/api/http';
import { rawDataToServerSubuser } from '@/api/server/users/getServerSubusers';
import { Subuser, SubuserFileAccess } from '@/state/server/subusers';

interface Params {
    email: string;
    permissions: string[];
    fileAccess: SubuserFileAccess;
}

export default (uuid: string, params: Params, subuser?: Subuser): Promise<Subuser> => {
    return new Promise((resolve, reject) => {
        http.post(`/api/client/servers/${uuid}/users${subuser ? `/${subuser.uuid}` : ''}`, {
            email: params.email,
            permissions: params.permissions,
            file_access: params.fileAccess,
        })
            .then((data) => resolve(rawDataToServerSubuser(data.data)))
            .catch(reject);
    });
};
