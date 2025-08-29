export class ResponseHandler {
    static isValidJSON(text: string): boolean {
        try {
            JSON.parse(text);
            return true;
        } catch (e) {
            return false;
        }
    }

    static handleApiResponse(response: Response): Promise<any> {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        return response.text().then(text => {
            if (!this.isValidJSON(text)) {
                throw new Error('Invalid JSON response received');
            }
            return JSON.parse(text);
        });
    }
}
