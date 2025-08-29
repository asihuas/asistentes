import { ResponseHandler } from '../utils/responseHandler';

export class ChatApi {
    private baseUrl: string;

    constructor(baseUrl: string) {
        this.baseUrl = baseUrl;
    }

    async sendMessage(message: string): Promise<any> {
        try {
            const response = await fetch(`${this.baseUrl}/chat`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ message })
            });

            return ResponseHandler.handleApiResponse(response);
        } catch (error) {
            console.error('Error sending message:', error);
            throw error;
        }
    }
}
