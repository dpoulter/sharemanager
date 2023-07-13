import yfinance as yf

# Set the ticker symbol for the financial instrument you want to retrieve data for
ticker = "AAPL"  # Example: Apple Inc. (AAPL)

# Create a ticker object
stock = yf.Ticker(ticker)

# Retrieve historical data
data = stock.history(period="1y")  # Adjust the period as needed (1y = 1 year)

# Convert the data to JSON format
json_data = data.to_json()

# Print the JSON data

info = stock.info
print (info.keys())
print(info["marketCap"])
